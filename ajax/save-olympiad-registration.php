<?php
/**
 * Save Olympiad Registration AJAX Endpoint
 * Creates an olympiad diploma order and adds it to the session cart.
 *
 * Input (POST): result_id, template_id, email, fio, phone, organization,
 *               city, participation_date, competition_type, placement,
 *               has_supervisor, supervisor_name, supervisor_email,
 *               supervisor_organization, csrf_token
 *
 * Output (JSON): { success: true/false, registration_id: X, message: '...' }
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/OlympiadQuiz.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/ChatpushClient.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../includes/session.php';

// ---------------------------------------------------------------------------
// 1. CSRF validation
// ---------------------------------------------------------------------------

$csrfToken = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($csrfToken)) {
    echo json_encode([
        'success' => false,
        'message' => 'Недействительный токен безопасности. Обновите страницу и попробуйте снова.'
    ]);
    exit;
}

// ---------------------------------------------------------------------------
// 3. Basic input validation
// ---------------------------------------------------------------------------

$validator = new Validator($_POST);
$validator->required(['result_id', 'template_id', 'email', 'fio', 'organization', 'participation_date'])
          ->email('email')
          ->maxLength('fio', 55)
          ->maxLength('organization', 255)
          ->maxLength('city', 100)
          ->maxLength('supervisor_organization', 255)
          ->maxLength('supervisor_tab_city', 255)
          ->maxLength('supervisor_tab_organization', 255)
          ->maxLength('supervisor_tab_name', 55)
          ->date('participation_date');

if ($validator->fails()) {
    echo json_encode([
        'success' => false,
        'message' => $validator->getFirstError()
    ]);
    exit;
}

try {
    $data = $validator->getData();

    // Телефон обязателен для олимпиад: номер нужен, чтобы наполнить базу и слать
    // уведомления в «Макс». Нормализуем к 79XXXXXXXXX; невалидный/пустой → ошибка.
    $phone = ChatpushClient::normalizePhone($_POST['phone'] ?? null);
    if ($phone === null) {
        echo json_encode([
            'success' => false,
            'message' => 'Укажите корректный номер телефона для связи (например, +7 900 123-45-67).'
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // 4. Validate result: exists, user owns it, placement exists
    // ------------------------------------------------------------------

    $quizObj = new OlympiadQuiz($db);
    $result  = $quizObj->getResultById((int)$data['result_id']);

    if (!$result) {
        echo json_encode([
            'success' => false,
            'message' => 'Результат олимпиады не найден.'
        ]);
        exit;
    }

    $userId = (int)$result['user_id'];
    if (!canAccessPurchaseResource($db, 'olympiad_result', (int)$result['id'], $userId)) {
        echo json_encode([
            'success' => false,
            'message' => 'У вас нет доступа к этому результату.'
        ]);
        exit;
    }

    if (!in_array($result['placement'], ['1', '2', '3'], true)) {
        echo json_encode([
            'success' => false,
            'message' => 'Диплом доступен только для призовых мест (1, 2 или 3 место).'
        ]);
        exit;
    }

    // Защита от дублирующей регистрации на тот же olympiad_result_id:
    // если у пользователя уже есть оплаченный/готовый диплом на этот результат,
    // не даём создавать ещё одну pending-регистрацию (которая запустит цепочку
    // напоминаний «оплатите диплом» — alert #87, май 2026).
    $existingPaid = $db->prepare(
        "SELECT id, status FROM olympiad_registrations
         WHERE olympiad_result_id = ?
           AND user_id = ?
           AND status IN ('paid','diploma_ready')
         ORDER BY id DESC LIMIT 1"
    );
    $existingPaid->execute([$result['id'], $userId]);
    $paidReg = $existingPaid->fetch(PDO::FETCH_ASSOC);
    if ($paidReg) {
        echo json_encode([
            'success'          => false,
            'already_paid'     => true,
            'registration_id'  => (int)$paidReg['id'],
            'message'          => 'Диплом по этому результату уже оплачен и готов. Откройте личный кабинет → раздел «Олимпиады», чтобы скачать.',
            'cabinet_url'      => '/kabinet/',
        ]);
        exit;
    }

    // ------------------------------------------------------------------
    // 5. Create or update user profile
    // ------------------------------------------------------------------

    $userObj  = new User($db);
    $userInfo = $userObj->getById($userId);
    if (!$userInfo || strcasecmp($data['email'], $userInfo['email']) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Укажите почту, использованную для прохождения олимпиады.']);
        exit;
    }
    if (verifiedAccountId($db) === $userId) {
        $fields = array_filter(['city' => $data['city'] ?? '', 'organization' => $data['organization'] ?? '']);
        if (empty($userInfo['full_name'])) $fields['full_name'] = $data['fio'];
        if (empty($userInfo['phone']) && $phone) $fields['phone'] = $phone;
        if ($fields) $userObj->update($userId, $fields);
    }

    // ------------------------------------------------------------------
    // 6. Create olympiad registration (diploma order)
    // ------------------------------------------------------------------

    $supervisorTab = ($data['current_tab'] ?? '') === 'supervisor';
    if ($supervisorTab) {
        $data['supervisor_name'] = $data['supervisor_tab_name'] ?? '';
        $data['supervisor_organization'] = $data['supervisor_tab_organization'] ?? '';
        $data['supervisor_email'] = $data['supervisor_tab_email'] ?? '';
    }
    $hasSupervisor = ($supervisorTab || !empty($data['has_supervisor'])) ? 1 : 0;
    $supervisorName = $hasSupervisor ? trim($data['supervisor_name'] ?? '') : null;

    // If supervisor name is empty but checkbox was checked, fall back
    if ($hasSupervisor && empty($supervisorName)) {
        $hasSupervisor = 0;
    }

    // UTM-атрибуция первого клика
    $utmSource   = mb_substr(trim($_POST['utm_source'] ?? ''), 0, 255) ?: null;
    $utmMedium   = mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 255) ?: null;
    $utmCampaign = mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 255) ?: null;
    $utmContent  = mb_substr(trim($_POST['utm_content'] ?? ''), 0, 255) ?: null;
    $utmTerm     = mb_substr(trim($_POST['utm_term'] ?? ''), 0, 255) ?: null;

    // Привязка визита к пользователю
    $visitId = intval($_POST['visit_id'] ?? 0);
    if ($visitId && $userId) {
        $dbObj = new Database($db);
        $dbObj->execute("UPDATE visits SET user_id = ? WHERE id = ? AND user_id IS NULL", [$userId, $visitId]);
    }

    $registrationObj = new OlympiadRegistration($db);

    $registrationId = $registrationObj->create([
        'user_id'                 => $userId,
        'participant_name'        => mb_substr(trim($data['fio']), 0, 55),
        'phone'                   => $phone,
        'olympiad_id'             => $result['olympiad_id'],
        'olympiad_result_id'      => $result['id'],
        'diploma_template_id'     => (int)$data['template_id'],
        'placement'               => $result['placement'],
        'score'                   => $result['score'],
        'organization'            => $data['organization'] ?? '',
        'city'                    => $data['city'] ?? '',
        'competition_type'        => $data['competition_type'] ?? 'всероссийская',
        'participation_date'      => $data['participation_date'],
        'has_supervisor'          => $hasSupervisor,
        'supervisor_name'         => $supervisorName,
        'supervisor_city'         => $supervisorTab ? ($data['supervisor_tab_city'] ?? '') : null,
        'supervisor_email'        => $hasSupervisor ? ($data['supervisor_email'] ?? null) : null,
        'supervisor_organization' => $hasSupervisor ? ($data['supervisor_organization'] ?? null) : null,
        'utm_source'              => $utmSource,
        'utm_medium'              => $utmMedium,
        'utm_campaign'            => $utmCampaign,
        'utm_content'             => $utmContent,
        'utm_term'                => $utmTerm,
    ]);

    // ------------------------------------------------------------------
    // 7. Add to session cart
    // ------------------------------------------------------------------

    grantGuestResource('olympiad_reg', (int)$registrationId, $userId);
    addOlympiadRegistrationToCart($registrationId);

    // Sync specializations to user profile for recommendations
    syncUserSpecializations($db, $userId, 'olympiad_specializations', 'olympiad_id', $result['olympiad_id']);

    // Schedule olympiad email chain for unpaid diploma order + cancel quiz reminder
    try {
        require_once __DIR__ . '/../classes/OlympiadEmailChain.php';
        $emailChain = new OlympiadEmailChain($db);
        $emailChain->scheduleForRegistration($registrationId, $userId);
        $emailChain->cancelDiplomaReminder($userId, $result['olympiad_id']);
    } catch (Exception $e) {
        error_log('Olympiad email chain schedule error: ' . $e->getMessage());
    }

    // ------------------------------------------------------------------
    // 8. Build e-commerce tracking payload and respond
    // ------------------------------------------------------------------

    $diplomaPrice = floatval($result['diploma_price'] ?? 229);

    echo json_encode([
        'success'         => true,
        'registration_id' => $registrationId,
        'message'         => 'Диплом олимпиады успешно оформлен',
        'ecommerce'       => [
            'id'       => $result['olympiad_id'],
            'name'     => $result['olympiad_title'],
            'price'    => $diplomaPrice,
            'category' => 'Олимпиады',
            'placement'=> $result['placement'],
        ],
    ]);

} catch (Exception $e) {
    error_log('Olympiad registration error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка при оформлении диплома. Попробуйте снова.'
    ]);
}

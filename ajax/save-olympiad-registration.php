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
// 2. Require authenticated user
// ---------------------------------------------------------------------------

$userId = getUserId();
if (!$userId) {
    echo json_encode([
        'success' => false,
        'message' => 'Сессия истекла. Пожалуйста, войдите снова.'
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

    if ((int)$result['user_id'] !== (int)$userId) {
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

    // ------------------------------------------------------------------
    // 5. Create or update user profile
    // ------------------------------------------------------------------

    $userObj  = new User($db);
    $userInfo = $userObj->findByEmail($data['email']);

    if (!$userInfo) {
        // Create new user
        $newUserId = $userObj->create([
            'email'        => $data['email'],
            'full_name'    => $data['fio'],
            'phone'        => $data['phone'] ?? null,
            'city'         => $data['city'] ?? null,
            'organization' => $data['organization'] ?? null,
        ]);
        $userId = $newUserId;
    } else {
        // Update existing user with any newly provided fields
        $userId = $userInfo['id'];
        $updateFields = ['full_name' => $data['fio']];

        if (!empty($data['phone'])) {
            $updateFields['phone'] = $data['phone'];
        }
        if (!empty($data['city'])) {
            $updateFields['city'] = $data['city'];
        }
        if (!empty($data['organization'])) {
            $updateFields['organization'] = $data['organization'];
        }

        $userObj->update($userId, $updateFields);
    }

    // ------------------------------------------------------------------
    // 6. Create olympiad registration (diploma order)
    // ------------------------------------------------------------------

    $hasSupervisor  = !empty($data['has_supervisor']) ? 1 : 0;
    $supervisorName = $hasSupervisor ? trim($data['supervisor_name'] ?? '') : null;

    // If supervisor name is empty but checkbox was checked, fall back
    if ($hasSupervisor && empty($supervisorName)) {
        $hasSupervisor = 0;
    }

    $registrationObj = new OlympiadRegistration($db);

    $registrationId = $registrationObj->create([
        'user_id'                 => $userId,
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
        'supervisor_email'        => $hasSupervisor ? ($data['supervisor_email'] ?? null) : null,
        'supervisor_organization' => $hasSupervisor ? ($data['supervisor_organization'] ?? null) : null,
    ]);

    // ------------------------------------------------------------------
    // 7. Add to session cart
    // ------------------------------------------------------------------

    addOlympiadRegistrationToCart($registrationId);

    // Sync specializations to user profile for recommendations
    syncUserSpecializations($db, $userId, 'olympiad_specializations', 'olympiad_id', $result['olympiad_id']);

    // Keep user_id in session
    $_SESSION['user_id'] = $userId;

    // ------------------------------------------------------------------
    // 8. Build e-commerce tracking payload and respond
    // ------------------------------------------------------------------

    $diplomaPrice = floatval($result['diploma_price'] ?? 169);

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

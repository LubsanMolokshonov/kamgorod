<?php
/**
 * Save Group Registration AJAX Endpoint
 * Групповая заявка: учитель оформляет дипломы сразу на группу учеников (2–30)
 * в одном конкурсе или олимпиаде. Создаёт N обычных registrations /
 * olympiad_registrations, связанных общим group_batch_id, и кладёт их в корзину.
 *
 * Прогрессивная скидка по размеру группы считается на этапе оплаты
 * (ajax/create-payment.php) по зафиксированному в participant_groups тарифу.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/group-pricing.php';

// CSRF
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный CSRF токен']);
    exit;
}

// Тип продукта
$productType = ($_POST['product_type'] ?? '') === 'olympiad' ? 'olympiad' : 'competition';
$productId   = (int)($_POST['product_id'] ?? 0);

// Общая валидация
$validator = new Validator($_POST);
$required = ['email', 'organization', 'participation_date', 'template_id'];
if ($productType === 'competition') {
    $required[] = 'nomination';
}
$validator->required($required)
          ->email('email')
          ->date('participation_date');

if ($validator->fails() || $productId <= 0) {
    echo json_encode(['success' => false, 'message' => $validator->getFirstError() ?: 'Не выбран продукт']);
    exit;
}

// Разбор и нормализация списка участников
$rawParticipants = $_POST['participants'] ?? [];
if (!is_array($rawParticipants)) {
    $rawParticipants = [];
}

$participants = [];
foreach ($rawParticipants as $row) {
    if (!is_array($row)) {
        continue;
    }
    $fio = mb_substr(trim($row['fio'] ?? ''), 0, 55);
    if ($fio === '') {
        continue; // пустые строки таблицы отбрасываем
    }
    $placement = trim($row['placement'] ?? '');
    // Допустимые значения места: 1/2/3 (+ «участник» для конкурса)
    $allowedPlacements = $productType === 'competition'
        ? ['1', '2', '3', 'участник']
        : ['1', '2', '3'];
    if (!in_array($placement, $allowedPlacements, true)) {
        $placement = $productType === 'competition' ? 'участник' : '3';
    }
    $participants[] = [
        'fio'        => $fio,
        'placement'  => $placement,
        'work_title' => mb_substr(trim($row['work_title'] ?? ''), 0, 255) ?: null,
    ];
    if (count($participants) >= GROUP_MAX_PARTICIPANTS) {
        break; // жёсткий лимит сверху
    }
}

if (count($participants) < GROUP_MIN_PARTICIPANTS) {
    echo json_encode([
        'success' => false,
        'message' => 'Добавьте минимум ' . GROUP_MIN_PARTICIPANTS . ' участников группы'
    ]);
    exit;
}

try {
    $data = $validator->getData();

    // Руководитель группы = учитель (владелец аккаунта)
    $supervisorName = !empty($data['supervisor_name']) ? mb_substr(trim($data['supervisor_name']), 0, 55) : null;
    $supervisorEmail = !empty($data['supervisor_email']) ? trim($data['supervisor_email']) : null;
    $supervisorOrg  = !empty($data['supervisor_organization']) ? trim($data['supervisor_organization']) : null;

    $accountOwnerName = $supervisorName ?: null; // в группе владелец — учитель
    $accountOwnerOrg  = $supervisorOrg ?: ($data['organization'] ?? null);

    // find-or-create teacher user
    $userObj = new User($db);
    $user = $userObj->findByEmail($data['email']);
    if (!$user) {
        $userId = $userObj->create([
            'email'        => $data['email'],
            'full_name'    => $accountOwnerName,
            'phone'        => $data['phone'] ?? null,
            'city'         => $data['city'] ?? null,
            'organization' => $accountOwnerOrg,
        ]);
    } else {
        $userId = $user['id'];
        $updateFields = [];
        if (empty($user['full_name']) && !empty($accountOwnerName)) {
            $updateFields['full_name'] = $accountOwnerName;
        }
        if (empty($user['city']) && !empty($data['city'])) {
            $updateFields['city'] = $data['city'];
        }
        if (empty($user['organization']) && !empty($accountOwnerOrg)) {
            $updateFields['organization'] = $accountOwnerOrg;
        }
        if (!empty($updateFields)) {
            $userObj->update($userId, $updateFields);
        }
    }

    $size    = count($participants);
    $percent = groupDiscountPercent($size);

    // Dedup-гард: повторный сабмит той же группы за 30 мин → вернуть существующую.
    $groupObj = new ParticipantGroup($db);
    $duplicate = $groupObj->findRecentDuplicate((int)$userId, $productType, $productId, $size, 30);
    if ($duplicate) {
        echo json_encode([
            'success'          => true,
            'batch_id'         => $duplicate['id'],
            'count'            => (int)$duplicate['size'],
            'discount_percent' => (int)$duplicate['discount_percent'],
            'duplicate'        => true,
            'redirect_url'     => '/pages/cart.php',
            'message'          => 'Группа уже оформлена',
        ]);
        exit;
    }

    // UTM-атрибуция
    $utm = [
        'utm_source'   => mb_substr(trim($_POST['utm_source'] ?? ''), 0, 255) ?: null,
        'utm_medium'   => mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 255) ?: null,
        'utm_campaign' => mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 255) ?: null,
        'utm_content'  => mb_substr(trim($_POST['utm_content'] ?? ''), 0, 255) ?: null,
        'utm_term'     => mb_substr(trim($_POST['utm_term'] ?? ''), 0, 255) ?: null,
    ];

    $competitionType = !empty($data['competition_type']) ? trim($data['competition_type']) : null;
    $templateId      = (int)$data['template_id'];

    // Транзакция: группа + N регистраций
    $db->beginTransaction();

    $batchId = ParticipantGroup::generateBatchId();
    $groupObj->create([
        'id'               => $batchId,
        'user_id'          => $userId,
        'product_type'     => $productType,
        'product_id'       => $productId,
        'size'             => $size,
        'discount_percent' => $percent,
    ]);

    $registrationObj = new Registration($db);
    $olympRegObj     = new OlympiadRegistration($db);
    $createdIds      = [];

    foreach ($participants as $index => $p) {
        // Руководительский диплом генерируем ровно один на группу — флаг
        // has_supervisor ставим только на первую строку. Имя руководителя
        // показываем на дипломе каждого ученика (рендерится по supervisor_name).
        $hasSupervisor = ($supervisorName && $index === 0) ? 1 : 0;

        if ($productType === 'competition') {
            $id = $registrationObj->create(array_merge([
                'user_id'                 => $userId,
                'group_batch_id'          => $batchId,
                'participant_name'        => $p['fio'],
                'competition_id'          => $productId,
                'nomination'              => $data['nomination'],
                'work_title'              => $p['work_title'],
                'competition_type'        => $competitionType,
                'placement'               => $p['placement'],
                'participation_date'      => $data['participation_date'],
                'diploma_template_id'     => $templateId,
                'has_supervisor'          => $hasSupervisor,
                'supervisor_name'         => $supervisorName,
                'supervisor_email'        => $supervisorEmail,
                'supervisor_organization' => $supervisorOrg,
            ], $utm));
            $id = (int)$id;
            addToCart($id);
        } else {
            $id = $olympRegObj->create(array_merge([
                'user_id'                 => $userId,
                'group_batch_id'          => $batchId,
                'participant_name'        => $p['fio'],
                'olympiad_id'             => $productId,
                'olympiad_result_id'      => null, // групповой диплом без прохождения теста
                'diploma_template_id'     => $templateId,
                'placement'               => $p['placement'],
                'score'                   => 0, // тест не проходился; score на дипломе не печатается

                'organization'            => $data['organization'] ?? '',
                'city'                    => $data['city'] ?? '',
                'competition_type'        => $competitionType ?: 'всероссийская',
                'participation_date'      => $data['participation_date'],
                'has_supervisor'          => $hasSupervisor,
                'supervisor_name'         => $supervisorName,
                'supervisor_email'        => $supervisorEmail,
                'supervisor_organization' => $supervisorOrg,
            ], $utm));
            $id = (int)$id;
            addOlympiadRegistrationToCart($id);
        }
        $createdIds[] = $id;
    }

    $db->commit();

    // Привязка визита к пользователю (после commit — некритично)
    $visitId = intval($_POST['visit_id'] ?? 0);
    if ($visitId && $userId) {
        try {
            $dbObj = new Database($db);
            $dbObj->execute("UPDATE visits SET user_id = ? WHERE id = ? AND user_id IS NULL", [$userId, $visitId]);
        } catch (Exception $e) {
            error_log('Group registration visit bind error: ' . $e->getMessage());
        }
    }

    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $data['email'];

    echo json_encode([
        'success'          => true,
        'batch_id'         => $batchId,
        'ids'              => $createdIds,
        'count'            => $size,
        'discount_percent' => $percent,
        'redirect_url'     => '/pages/cart.php',
        'message'          => 'Групповая заявка создана',
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('Group registration error: ' . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка при оформлении группы. Попробуйте снова.'
    ]);
}

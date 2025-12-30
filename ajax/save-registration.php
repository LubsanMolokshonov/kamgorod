<?php
/**
 * Save Registration AJAX Endpoint
 * Creates or updates user and creates registration
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/Validator.php';

// Validate inputs
$validator = new Validator($_POST);
$validator->required(['fio', 'email', 'competition_id', 'nomination', 'organization', 'participation_date', 'template_id'])
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
    // Sanitize data
    $data = $validator->getData();

    // Create or get user
    $userObj = new User($db);
    $user = $userObj->findByEmail($data['email']);

    if (!$user) {
        // Create new user
        $userId = $userObj->create([
            'email' => $data['email'],
            'full_name' => $data['fio'],
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'organization' => $data['organization'] ?? null
        ]);
    } else {
        // Update existing user
        $userId = $user['id'];
        $userObj->update($userId, [
            'full_name' => $data['fio'],
            'phone' => $data['phone'] ?? null,
            'city' => $data['city'] ?? null,
            'organization' => $data['organization'] ?? null
        ]);
    }

    // Check if user already registered for this competition
    $registrationObj = new Registration($db);
    if ($registrationObj->userHasRegistration($userId, $data['competition_id'])) {
        echo json_encode([
            'success' => false,
            'message' => 'Вы уже зарегистрированы на этот конкурс. Проверьте корзину.'
        ]);
        exit;
    }

    // Determine if supervisor exists based on supervisor_name field
    $supervisorName = !empty($data['supervisor_name']) ? trim($data['supervisor_name']) : null;
    $hasSupervisor = !empty($supervisorName) ? 1 : 0;

    // Create registration
    $registrationId = $registrationObj->create([
        'user_id' => $userId,
        'competition_id' => $data['competition_id'],
        'nomination' => $data['nomination'],
        'work_title' => $data['work_title'] ?? null,
        'competition_type' => $data['competition_type'] ?? null,
        'placement' => $data['placement'] ?? null,
        'participation_date' => $data['participation_date'],
        'diploma_template_id' => $data['template_id'],
        'has_supervisor' => $hasSupervisor,
        'supervisor_name' => $supervisorName,
        'supervisor_email' => $data['supervisor_email'] ?? null,
        'supervisor_organization' => $data['supervisor_organization'] ?? null
    ]);

    // Add to session cart
    if (!isset($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }

    if (!in_array($registrationId, $_SESSION['cart'])) {
        $_SESSION['cart'][] = $registrationId;
    }

    $_SESSION['user_id'] = $userId;

    echo json_encode([
        'success' => true,
        'registration_id' => $registrationId,
        'message' => 'Регистрация успешно создана'
    ]);

} catch (Exception $e) {
    error_log('Registration error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Произошла ошибка при сохранении регистрации. Попробуйте снова.'
    ]);
}

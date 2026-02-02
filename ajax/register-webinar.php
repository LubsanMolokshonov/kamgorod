<?php
/**
 * AJAX: Register for Webinar
 * Регистрация на вебинар с интеграцией Bitrix24
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../includes/session.php';

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // Get and validate input
    $webinarId = intval($_POST['webinar_id'] ?? 0);
    $fullName = trim($_POST['full_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $position = trim($_POST['position'] ?? '');
    $city = trim($_POST['city'] ?? '');

    // UTM parameters
    $utmSource = trim($_POST['utm_source'] ?? '');
    $utmMedium = trim($_POST['utm_medium'] ?? '');
    $utmCampaign = trim($_POST['utm_campaign'] ?? '');

    // Validate required fields
    if (!$webinarId) {
        throw new Exception('Вебинар не указан');
    }
    if (!$fullName) {
        throw new Exception('Укажите ваше ФИО');
    }
    if (!$email) {
        throw new Exception('Укажите email');
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new Exception('Некорректный email адрес');
    }

    // Check for Cyrillic in email
    if (preg_match('/[а-яА-ЯёЁ]/u', $email)) {
        throw new Exception('Email не должен содержать кириллицу');
    }

    // Get webinar
    $webinarObj = new Webinar($db);
    $webinar = $webinarObj->getById($webinarId);

    if (!$webinar || !$webinar['is_active']) {
        throw new Exception('Вебинар не найден или недоступен');
    }

    // Check if webinar is in the past (except for autowebinars)
    if ($webinar['status'] === 'completed' && !$webinar['video_url']) {
        throw new Exception('Регистрация на этот вебинар закрыта');
    }

    // Initialize registration class
    $registrationObj = new WebinarRegistration($db);

    // Check if already registered
    if ($registrationObj->isRegistered($webinarId, $email)) {
        echo json_encode([
            'success' => true,
            'message' => 'Вы уже зарегистрированы на этот вебинар',
            'already_registered' => true,
            'email' => $email,
            'webinar_title' => $webinar['title'],
            'broadcast_url' => $webinar['broadcast_url'] ?? ''
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Find or create user
    $userObj = new User($db);
    $user = $userObj->findByEmail($email);
    $userId = null;

    if ($user) {
        $userId = $user['id'];
    } else {
        // Create new user
        $userId = $userObj->create([
            'email' => $email,
            'full_name' => $fullName,
            'phone' => $phone,
            'organization' => $organization,
            'city' => $city
        ]);
    }

    // Create registration
    $registrationData = [
        'webinar_id' => $webinarId,
        'user_id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'organization' => $organization,
        'position' => $position,
        'city' => $city,
        'utm_source' => $utmSource,
        'utm_medium' => $utmMedium,
        'utm_campaign' => $utmCampaign,
        'registration_source' => 'website'
    ];

    $registrationId = $registrationObj->create($registrationData);

    if (!$registrationId) {
        throw new Exception('Ошибка создания регистрации');
    }

    // Note: Bitrix24 sync is handled automatically in WebinarRegistration::create()

    // Set session user if not logged in
    if (!getUserId()) {
        setUserId($userId);
        $_SESSION['user_email'] = $email;
    }

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Вы успешно зарегистрированы!',
        'registration_id' => $registrationId,
        'webinar_id' => $webinarId,
        'webinar_title' => $webinar['title'],
        'email' => $email,
        'broadcast_url' => $webinar['broadcast_url'] ?? '',
        'scheduled_at' => $webinar['scheduled_at']
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

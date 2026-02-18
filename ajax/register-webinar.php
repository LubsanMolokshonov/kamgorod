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
require_once __DIR__ . '/../classes/WebinarEmailJourney.php';
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
    $institutionTypeId = intval($_POST['institution_type_id'] ?? 0);
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

    // Validate institution type
    if (!$institutionTypeId) {
        throw new Exception('Укажите тип учреждения');
    }

    // Verify institution type exists
    require_once __DIR__ . '/../classes/AudienceType.php';
    $audienceTypeObj = new AudienceType($db);
    $institutionType = $audienceTypeObj->getById($institutionTypeId);
    if (!$institutionType) {
        throw new Exception('Выбран некорректный тип учреждения');
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
    $isNewUser = !$user;

    if ($user) {
        $userId = $user['id'];
        // Update user's institution type if provided and different
        if ($institutionTypeId && $user['institution_type_id'] != $institutionTypeId) {
            $userObj->update($userId, ['institution_type_id' => $institutionTypeId]);
        }
    } else {
        // Create new user
        $userId = $userObj->create([
            'email' => $email,
            'full_name' => $fullName,
            'phone' => $phone,
            'organization' => null,
            'city' => $city,
            'institution_type_id' => $institutionTypeId ?: null
        ]);
    }

    // Generate session_token for auto-login (like competitions)
    $sessionToken = $userObj->generateSessionToken($userId);

    // Set cookie for auto-login (30 days)
    setcookie('session_token', $sessionToken, [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Determine if autowebinar before creating registration
    $isAutowebinar = $webinar['status'] === 'autowebinar';

    // Create registration (skip Bitrix24 for autowebinars — instant access, no CRM deal needed)
    $registrationData = [
        'webinar_id' => $webinarId,
        'user_id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'organization' => null,
        'position' => null,
        'city' => $city,
        'utm_source' => $utmSource,
        'utm_medium' => $utmMedium,
        'utm_campaign' => $utmCampaign,
        'registration_source' => 'website',
        'skip_bitrix24' => $isAutowebinar
    ];

    $registrationId = $registrationObj->create($registrationData);

    if (!$registrationId) {
        throw new Exception('Ошибка создания регистрации');
    }

    // Schedule email journey
    if (!$isAutowebinar) {
        try {
            $emailJourney = new WebinarEmailJourney($db);
            $emailJourney->scheduleForRegistration($registrationId);
            // Send confirmation email immediately
            $emailJourney->sendConfirmationEmail($registrationId);
        } catch (Exception $emailError) {
            // Log error but don't fail registration
            error_log("Webinar Email Journey Error: " . $emailError->getMessage());
        }
    } else {
        // Autowebinar: send welcome email with magic link
        try {
            require_once __DIR__ . '/../classes/AutowebinarEmailChain.php';
            $awChain = new AutowebinarEmailChain($db);
            $awChain->scheduleWelcomeEmail($registrationId);
            $awChain->sendWelcomeEmail($registrationId);
        } catch (Exception $emailError) {
            error_log("Autowebinar Email Chain Error: " . $emailError->getMessage());
        }
    }

    // Set session user if not logged in
    if (!getUserId()) {
        setUserId($userId);
        $_SESSION['user_email'] = $email;
    }
    $cabinetUrl = $isAutowebinar
        ? '/kabinet/avtovebinar/' . $registrationId
        : '/pages/cabinet.php?tab=webinars';

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Вы успешно зарегистрированы!',
        'registration_id' => $registrationId,
        'webinar_id' => $webinarId,
        'webinar_title' => $webinar['title'],
        'email' => $email,
        'broadcast_url' => $webinar['broadcast_url'] ?? '',
        'scheduled_at' => $webinar['scheduled_at'],
        'cabinet_created' => $isNewUser,
        'cabinet_url' => $cabinetUrl,
        'is_autowebinar' => $isAutowebinar,
        'autowebinar_url' => $isAutowebinar ? $cabinetUrl : null
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

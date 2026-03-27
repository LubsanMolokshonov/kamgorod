<?php
/**
 * AJAX: Course Enrollment
 * Запись на курс повышения квалификации с интеграцией Bitrix24
 */

header('Content-Type: application/json; charset=UTF-8');

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/Validator.php';
require_once __DIR__ . '/../includes/session.php';

try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // Validate inputs
    $validator = new Validator($_POST);
    $validator->required(['course_id', 'full_name', 'email', 'phone'])
              ->email('email')
              ->phone('phone')
              ->maxLength('full_name', 100);

    if ($validator->fails()) {
        throw new Exception($validator->getFirstError());
    }

    $data = $validator->getData();

    $courseId = intval($data['course_id']);
    $fullName = $data['full_name'];
    $email = $data['email'];
    $phone = $data['phone'];

    // UTM-метки, Яндекс.Метрика, страница-источник
    $utmSource = trim($_POST['utm_source'] ?? '');
    $utmMedium = trim($_POST['utm_medium'] ?? '');
    $utmCampaign = trim($_POST['utm_campaign'] ?? '');
    $utmContent = trim($_POST['utm_content'] ?? '');
    $utmTerm = trim($_POST['utm_term'] ?? '');
    $ymUid = trim($_POST['ym_uid'] ?? '');
    $sourcePage = trim($_POST['source_page'] ?? '');

    if (!$courseId) {
        throw new Exception('Курс не указан');
    }

    // Get course
    $courseObj = new Course($db);
    $course = $courseObj->getById($courseId);

    if (!$course || !$course['is_active']) {
        throw new Exception('Курс не найден или недоступен');
    }

    // Find or create user
    $userObj = new User($db);
    $user = $userObj->findByEmail($email);
    $userId = null;

    if ($user) {
        $userId = $user['id'];
        $userObj->update($userId, [
            'full_name' => $fullName,
            'phone' => $phone
        ]);
    } else {
        $userId = $userObj->create([
            'email' => $email,
            'full_name' => $fullName,
            'phone' => $phone
        ]);
    }

    // Check for duplicate enrollment
    $dbObj = new Database($db);
    $existing = $dbObj->queryOne(
        "SELECT id FROM course_enrollments WHERE course_id = ? AND email = ? AND status != 'cancelled'",
        [$courseId, $email]
    );

    if ($existing) {
        // Set session for auto-login
        if (!getUserId()) {
            setUserId($userId);
            $_SESSION['user_email'] = $email;
        }

        // Generate session_token for persistent login
        $sessionToken = $userObj->generateSessionToken($userId);
        setcookie('session_token', $sessionToken, [
            'expires' => time() + (30 * 24 * 60 * 60),
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax'
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Вы уже подали заявку на этот курс. Мы свяжемся с вами в ближайшее время.',
            'already_enrolled' => true,
            'cabinet_url' => '/kabinet/?tab=courses'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Save enrollment to database
    $enrollmentData = [
        'course_id' => $courseId,
        'user_id' => $userId,
        'full_name' => $fullName,
        'email' => $email,
        'phone' => $phone,
        'status' => 'new'
    ];
    if ($utmSource) $enrollmentData['utm_source'] = $utmSource;
    if ($utmMedium) $enrollmentData['utm_medium'] = $utmMedium;
    if ($utmCampaign) $enrollmentData['utm_campaign'] = $utmCampaign;
    if ($utmContent) $enrollmentData['utm_content'] = $utmContent;
    if ($utmTerm) $enrollmentData['utm_term'] = $utmTerm;
    if ($ymUid) $enrollmentData['ym_uid'] = $ymUid;
    if ($sourcePage) $enrollmentData['source_page'] = $sourcePage;

    $enrollmentId = $dbObj->insert('course_enrollments', $enrollmentData);

    // Set session
    if (!getUserId()) {
        setUserId($userId);
        $_SESSION['user_email'] = $email;
    }

    // Generate session_token for persistent login (like webinars)
    $sessionToken = $userObj->generateSessionToken($userId);
    setcookie('session_token', $sessionToken, [
        'expires' => time() + (30 * 24 * 60 * 60),
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    // Bitrix24 + email — в фоновом процессе, не блокирует ответ
    $scriptPath = __DIR__ . '/../scripts/process-course-enrollment.php';
    exec('php ' . escapeshellarg($scriptPath) . ' ' . intval($enrollmentId) . ' > /dev/null 2>&1 &');

    // Success response
    echo json_encode([
        'success' => true,
        'message' => 'Заявка успешно отправлена! Мы свяжемся с вами в ближайшее время.',
        'enrollment_id' => $enrollmentId,
        'course_title' => $course['title'],
        'cabinet_url' => '/kabinet/?tab=courses&enrolled=success',
        'ecommerce' => [
            'id'       => 'course-' . $course['id'],
            'name'     => $course['title'],
            'price'    => floatval($course['price']),
            'category' => 'Курсы',
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

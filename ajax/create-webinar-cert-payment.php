<?php
/**
 * Create Webinar Certificate Payment AJAX Handler
 * Adds webinar certificate to cart
 */

// Error handling - only log, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../includes/session.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

// Get registration ID
$registrationId = intval($_POST['registration_id'] ?? 0);
if (!$registrationId) {
    echo json_encode(['success' => false, 'message' => 'ID регистрации не указан']);
    exit;
}

try {
    $webinarRegObj = new WebinarRegistration($db);
    $webCertObj = new WebinarCertificate($db);
    $userObj = new User($db);

    // Get registration with webinar data
    $registration = $webinarRegObj->getById($registrationId);
    if (!$registration) {
        echo json_encode(['success' => false, 'message' => 'Регистрация не найдена']);
        exit;
    }

    // Verify ownership
    if (isset($_SESSION['user_id']) && $registration['user_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    // Get or create certificate
    $certificate = $webCertObj->getByRegistrationId($registrationId);
    if (!$certificate) {
        // Create new certificate
        $certificateId = $webCertObj->create([
            'webinar_id' => $registration['webinar_id'],
            'user_id' => $registration['user_id'],
            'registration_id' => $registrationId,
            'full_name' => $_POST['full_name'] ?? $registration['full_name'],
            'organization' => $_POST['organization'] ?? '',
            'position' => $_POST['position'] ?? '',
            'city' => $_POST['city'] ?? '',
            'hours' => $registration['certificate_hours'] ?? 2,
            'price' => $registration['certificate_price'] ?? 149.00,
            'template_id' => intval($_POST['template_id'] ?? 1),
        ]);
        $certificate = $webCertObj->getById($certificateId);
    } else {
        // Update with new form data
        $webCertObj->update($certificate['id'], [
            'full_name' => $_POST['full_name'] ?? $certificate['full_name'],
            'organization' => $_POST['organization'] ?? $certificate['organization'],
            'position' => $_POST['position'] ?? $certificate['position'],
            'city' => $_POST['city'] ?? $certificate['city'],
            'template_id' => intval($_POST['template_id'] ?? $certificate['template_id'] ?? 1),
        ]);
        $certificate = $webCertObj->getById($certificate['id']);
    }

    // Add to cart
    addWebinarCertificateToCart($certificate['id']);

    // Set user session
    $_SESSION['user_id'] = $registration['user_id'];
    $user = $userObj->getById($registration['user_id']);
    if ($user) {
        $_SESSION['user_email'] = $user['email'];
    }

    echo json_encode([
        'success' => true,
        'redirect_url' => '/pages/cart.php',
        'certificate_id' => $certificate['id'],
        'message' => 'Сертификат добавлен в корзину'
    ]);

} catch (Throwable $e) {
    error_log("Webinar certificate add to cart error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage()]);
}

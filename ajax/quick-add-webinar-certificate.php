<?php
/**
 * Quick-Add Webinar Certificate to Cart
 * Simplified version of create-webinar-cert-payment.php for cart cross-sell.
 * Creates a webinar certificate and adds it to the cart without redirecting.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
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

// User must be logged in
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться']);
    exit;
}

$registrationId = intval($_POST['registration_id'] ?? 0);
if (!$registrationId) {
    echo json_encode(['success' => false, 'message' => 'ID регистрации не указан']);
    exit;
}

try {
    $webinarRegObj = new WebinarRegistration($db);
    $webCertObj = new WebinarCertificate($db);

    // Get registration with webinar data
    $registration = $webinarRegObj->getById($registrationId);
    if (!$registration) {
        echo json_encode(['success' => false, 'message' => 'Регистрация не найдена']);
        exit;
    }

    // Verify ownership
    if ((int)$registration['user_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    // Get or create certificate
    $certificate = $webCertObj->getByRegistrationId($registrationId);
    if (!$certificate) {
        $certificateId = $webCertObj->create([
            'webinar_id' => $registration['webinar_id'],
            'user_id' => $userId,
            'registration_id' => $registrationId,
            'full_name' => $registration['full_name'],
            'organization' => $registration['organization'] ?? '',
            'position' => $registration['position'] ?? '',
            'city' => $registration['city'] ?? '',
            'hours' => $registration['certificate_hours'] ?? 2,
            'price' => $registration['certificate_price'] ?? 200.00,
            'template_id' => 1,
        ]);
    } else {
        $certificateId = $certificate['id'];
    }

    // Add to cart
    addWebinarCertificateToCart($certificateId);

    echo json_encode([
        'success' => true,
        'message' => 'Сертификат добавлен в корзину',
        'cart_count' => getCartCount(),
        'ecommerce' => [
            'id' => 'wc-' . ($registration['webinar_id'] ?? 0),
            'name' => $registration['webinar_title'] ?? '',
            'price' => $registration['certificate_price'] ?? 200,
            'category' => 'Вебинары',
        ],
    ]);

} catch (Throwable $e) {
    error_log("Quick-add webinar certificate error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при добавлении']);
}

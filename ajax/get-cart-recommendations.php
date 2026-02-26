<?php
/**
 * Get Cart Recommendations AJAX Handler
 * Returns personalized product recommendations based on cart items' audience type.
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
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/CartRecommendation.php';
require_once __DIR__ . '/../includes/session.php';

// Only accept GET
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Check if cart is empty
    if (isCartEmpty()) {
        echo json_encode(['success' => true, 'recommendations' => [], 'promotionHint' => null]);
        exit;
    }

    // Build allItems array (same logic as cart.php lines 32-85)
    $allItems = [];

    $registrations = getCart();
    $registrationObj = new Registration($db);
    foreach ($registrations as $regId) {
        $registration = $registrationObj->getById($regId);
        if ($registration) {
            $allItems[] = [
                'type' => 'registration',
                'id' => $regId,
                'price' => (float)$registration['competition_price'],
                'raw_data' => $registration,
            ];
        }
    }

    $certificates = getCartCertificates();
    $certObj = new PublicationCertificate($db);
    foreach ($certificates as $certId) {
        $cert = $certObj->getById($certId);
        if ($cert) {
            $allItems[] = [
                'type' => 'certificate',
                'id' => $cert['id'],
                'price' => (float)($cert['price'] ?? 299),
                'raw_data' => $cert,
            ];
        }
    }

    $webinarCertificates = getCartWebinarCertificates();
    $webCertObj = new WebinarCertificate($db);
    foreach ($webinarCertificates as $webCertId) {
        $webCert = $webCertObj->getById($webCertId);
        if ($webCert) {
            $allItems[] = [
                'type' => 'webinar_certificate',
                'id' => $webCert['id'],
                'price' => (float)($webCert['price'] ?? 200),
                'raw_data' => $webCert,
            ];
        }
    }

    if (empty($allItems)) {
        echo json_encode(['success' => true, 'recommendations' => [], 'promotionHint' => null]);
        exit;
    }

    $userId = $_SESSION['user_id'] ?? null;
    $recommendation = new CartRecommendation($db);

    $recommendations = $recommendation->getRecommendations($allItems, $userId, 3);
    $promotionHint = $recommendation->getPromotionHint(count($allItems));

    // Determine if adding 1 item would complete a 2+1 set
    $remaining = 3 - (count($allItems) % 3);
    if ($remaining === 3) {
        $remaining = 0;
    }
    $oneMoreForFree = ($remaining === 1);

    echo json_encode([
        'success' => true,
        'recommendations' => $recommendations,
        'promotionHint' => $promotionHint,
        'oneMoreForFree' => $oneMoreForFree,
        'cartCount' => count($allItems),
    ]);

} catch (Throwable $e) {
    error_log("Cart recommendations error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка загрузки рекомендаций',
        'debug_error' => $e->getMessage(),
        'debug_file' => basename($e->getFile()) . ':' . $e->getLine(),
    ]);
}

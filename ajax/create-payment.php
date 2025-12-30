<?php
/**
 * Create Payment AJAX Endpoint
 * Placeholder for Yookassa payment integration (Phase 5)
 *
 * This will be implemented in Phase 5 with actual Yookassa SDK
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Недействительный CSRF токен'
    ]);
    exit;
}

// Check if cart exists
if (isCartEmpty()) {
    echo json_encode([
        'success' => false,
        'message' => 'Корзина пуста'
    ]);
    exit;
}

// Calculate cart total
$registrationObj = new Registration($db);
$cartData = $registrationObj->calculateCartTotal($_SESSION['cart']);

// TEMPORARY BYPASS: Skip payment and mark registrations as paid
// This will be replaced with actual Yookassa integration in Phase 5

try {
    // Get user info from the first registration
    $userId = null;
    if (!empty($_SESSION['cart'])) {
        $firstRegId = $_SESSION['cart'][0];
        $stmt = $db->prepare("
            SELECT u.id, u.email
            FROM registrations r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$firstRegId]);
        $userResult = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($userResult) {
            $userId = $userResult['id'];
            $_SESSION['user_email'] = $userResult['email'];
            $_SESSION['user_id'] = $userId;
        }
    }

    // Mark all cart items as paid
    foreach ($_SESSION['cart'] as $registrationId) {
        $stmt = $db->prepare("UPDATE registrations SET status = 'paid' WHERE id = ?");
        $stmt->execute([$registrationId]);
    }

    // Generate auto-login token and set cookie (30 days)
    if ($userId) {
        $userObj = new User($db);
        $sessionToken = $userObj->generateSessionToken($userId);

        // Set cookie for 30 days
        setcookie(
            'session_token',
            $sessionToken,
            time() + (30 * 24 * 60 * 60), // 30 days
            '/',
            '',
            isset($_SERVER['HTTPS']), // Secure flag if HTTPS
            true // HttpOnly flag
        );
    }

    // Clear the cart
    $_SESSION['cart'] = [];

    // Redirect to cabinet with success parameter
    echo json_encode([
        'success' => true,
        'message' => 'Переход в личный кабинет...',
        'redirect_url' => '/pages/cabinet.php?payment=success'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при обработке: ' . $e->getMessage()
    ]);
}

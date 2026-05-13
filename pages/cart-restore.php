<?php
/**
 * Cart Restore Page
 * Восстанавливает $_SESSION['cart_*'] из failed-заказа по HMAC-токену
 * из recovery-письма. При необходимости авто-логинит пользователя.
 *
 * Входная точка: /r/<token> (см. .htaccess).
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/recovery-link-helper.php';
require_once __DIR__ . '/../includes/session.php';

$token = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
if ($token === '') {
    error_log('cart-restore: empty token');
    header('Location: /');
    exit;
}

$decoded = validateRecoveryToken($token);
if ($decoded === false) {
    error_log('cart-restore: invalid or expired token');
    header('Location: /');
    exit;
}

$orderId = (int)$decoded['order_id'];
$userId  = (int)$decoded['user_id'];

$orderObj = new Order($db);
$order = $orderObj->getById($orderId);

if (!$order || (int)$order['user_id'] !== $userId) {
    error_log("cart-restore: order #{$orderId} not found or user_id mismatch");
    header('Location: /');
    exit;
}

// Дополнительный sanity-check: токен может пройти HMAC, но заказ — старый.
$createdTs = strtotime($order['created_at'] ?? '');
if (!$createdTs || $createdTs < strtotime('-7 days')) {
    error_log("cart-restore: order #{$orderId} older than 7 days");
    header('Location: /');
    exit;
}

// Auto-login (паттерн из payment-success.php:34-49).
$userObj = new User($db);
$sessionToken = $userObj->generateSessionToken($userId);
setcookie(
    'session_token',
    $sessionToken,
    time() + (30 * 24 * 60 * 60),
    '/',
    '',
    isset($_SERVER['HTTPS']),
    true
);
$_SESSION['user_id'] = $userId;
$_SESSION['user_email'] = $order['email'] ?? null;

// Очищаем cart перед восстановлением, чтобы избежать смешения с предыдущим состоянием.
clearCart();

$restored = 0;
$skipped = 0;

foreach ($order['items'] as $item) {
    if (!empty($item['registration_id'])) {
        $regId = (int)$item['registration_id'];
        // Позиция жива: регистрация ещё в pending и не оплачена в другом succeeded-заказе.
        $row = (new Database($db))->queryOne(
            "SELECT r.id FROM registrations r
             WHERE r.id = ? AND r.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.registration_id = r.id AND o2.payment_status = 'succeeded'
               )",
            [$regId]
        );
        if ($row) {
            addToCart($regId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['certificate_id'])) {
        $certId = (int)$item['certificate_id'];
        $row = (new Database($db))->queryOne(
            "SELECT pc.id FROM publication_certificates pc
             WHERE pc.id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.certificate_id = pc.id AND o2.payment_status = 'succeeded'
               )",
            [$certId]
        );
        if ($row) {
            addCertificateToCart($certId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['webinar_certificate_id'])) {
        $wcId = (int)$item['webinar_certificate_id'];
        $row = (new Database($db))->queryOne(
            "SELECT wc.id FROM webinar_certificates wc
             WHERE wc.id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.webinar_certificate_id = wc.id AND o2.payment_status = 'succeeded'
               )",
            [$wcId]
        );
        if ($row) {
            addWebinarCertificateToCart($wcId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['olympiad_registration_id'])) {
        $orId = (int)$item['olympiad_registration_id'];
        $row = (new Database($db))->queryOne(
            "SELECT olr.id FROM olympiad_registrations olr
             WHERE olr.id = ?
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.olympiad_registration_id = olr.id AND o2.payment_status = 'succeeded'
               )",
            [$orId]
        );
        if ($row) {
            addOlympiadRegistrationToCart($orId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['course_enrollment_id'])) {
        // Курсы не имеют cart-слота — пропускаем (см. CLAUDE.md / план).
        error_log("cart-restore: order #{$orderId} contains course_enrollment_id, skipped (not supported)");
        $skipped++;
    }
}

error_log("cart-restore: order #{$orderId} restored={$restored}, skipped={$skipped}");

if ($restored === 0) {
    // Все позиции уже оплачены — вести в кабинет, а не в пустую корзину.
    header('Location: /pages/cabinet.php?tab=events');
    exit;
}

header('Location: /pages/cart.php');
exit;

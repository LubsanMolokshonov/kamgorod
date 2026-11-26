<?php
/**
 * Cart Restore Page
 * Восстанавливает $_SESSION['cart_*'] из failed-заказа по HMAC-токену
 * из recovery-письма и даёт доступ к указанному в письме заказу.
 *
 * Входная точка: /r/<token> (см. .htaccess).
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/recovery-link-helper.php';
require_once __DIR__ . '/../includes/course-payment-link.php';
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

$accountId = verifiedAccountId($db);
if ($accountId !== null && $accountId !== $userId) {
    http_response_code(403);
    exit('Ссылка принадлежит другому аккаунту. Откройте её после выхода из кабинета.');
}
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
grantGuestOrder($orderId, $userId);

$courseId = courseCheckoutEnrollmentId($order);
if ($courseId !== null) {
    $stmt = $db->prepare("SELECT id FROM course_enrollments WHERE id=? AND user_id=?");
    $stmt->execute([$courseId, $userId]);
    if ($stmt->fetchColumn()) {
        grantGuestResource('course_enrollment', $courseId, $userId);
        header('Location: ' . courseCheckoutPath($courseId), true, 303);
        exit;
    }
}
if (in_array($order['payment_status'], ['succeeded','refunded'], true)) {
    header('Location: /pages/payment-success.php?order_number=' . rawurlencode($order['order_number']), true, 303);
    exit;
}
// Новые добавления покупателя сохраняются. add*ToCart уже отсекают повторные позиции.

$restored = 0;
$skipped = 0;

foreach ($order['items'] as $item) {
    if (!empty($item['registration_id'])) {
        $regId = (int)$item['registration_id'];
        // Позиция жива: регистрация ещё в pending и не оплачена в другом succeeded-заказе.
        $row = (new Database($db))->queryOne(
            "SELECT r.id FROM registrations r
             WHERE r.id = ? AND r.user_id = ? AND r.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.registration_id = r.id AND o2.payment_status = 'succeeded'
               )",
            [$regId, $userId]
        );
        if ($row) {
            grantGuestResource('registration', $regId, $userId);
            addToCart($regId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['certificate_id'])) {
        $certId = (int)$item['certificate_id'];
        $row = (new Database($db))->queryOne(
            "SELECT pc.id FROM publication_certificates pc
             WHERE pc.id = ? AND pc.user_id = ? AND pc.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.certificate_id = pc.id AND o2.payment_status = 'succeeded'
               )",
            [$certId, $userId]
        );
        if ($row) {
            grantGuestResource('publication_cert', $certId, $userId);
            addCertificateToCart($certId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['webinar_certificate_id'])) {
        $wcId = (int)$item['webinar_certificate_id'];
        $row = (new Database($db))->queryOne(
            "SELECT wc.id FROM webinar_certificates wc
             WHERE wc.id = ? AND wc.user_id = ? AND wc.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.webinar_certificate_id = wc.id AND o2.payment_status = 'succeeded'
               )",
            [$wcId, $userId]
        );
        if ($row) {
            grantGuestResource('webinar_cert', $wcId, $userId);
            addWebinarCertificateToCart($wcId);
            $restored++;
        } else {
            $skipped++;
        }
    } elseif (!empty($item['olympiad_registration_id'])) {
        $orId = (int)$item['olympiad_registration_id'];
        $row = (new Database($db))->queryOne(
            "SELECT olr.id FROM olympiad_registrations olr
             WHERE olr.id = ? AND olr.user_id = ? AND olr.status = 'pending'
               AND NOT EXISTS (
                   SELECT 1 FROM order_items oi2
                   JOIN orders o2 ON oi2.order_id = o2.id
                   WHERE oi2.olympiad_registration_id = olr.id AND o2.payment_status = 'succeeded'
               )",
            [$orId, $userId]
        );
        if ($row) {
            grantGuestResource('olympiad_reg', $orId, $userId);
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

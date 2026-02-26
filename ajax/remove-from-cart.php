<?php
/**
 * Remove Item from Cart AJAX Endpoint
 * Removes registration or certificate from session cart
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

// Get item IDs
$registrationId = $_POST['registration_id'] ?? null;
$certificateId = $_POST['certificate_id'] ?? null;
$webinarCertificateId = $_POST['webinar_certificate_id'] ?? null;

// Handle webinar certificate removal
if ($webinarCertificateId) {
    // Получить данные сертификата для e-commerce перед удалением
    $webCertEcommerce = null;
    $stmt = $db->prepare("
        SELECT wc.id, wc.webinar_id, wc.price, w.title as webinar_title
        FROM webinar_certificates wc
        JOIN webinars w ON wc.webinar_id = w.id
        WHERE wc.id = ?
    ");
    $stmt->execute([$webinarCertificateId]);
    $webCertData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($webCertData) {
        $webCertEcommerce = [
            'id' => 'wc-' . $webCertData['webinar_id'],
            'name' => $webCertData['webinar_title'],
            'price' => $webCertData['price'],
            'category' => 'Вебинары'
        ];
    }

    if (removeWebinarCertificateFromCart($webinarCertificateId)) {
        echo json_encode([
            'success' => true,
            'message' => 'Сертификат удален из корзины',
            'cart_count' => getCartCount(),
            'ecommerce' => $webCertEcommerce
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Сертификат не найден в корзине'
        ]);
    }
    exit;
}

// Handle certificate removal
if ($certificateId) {
    // Получить данные свидетельства для e-commerce перед удалением
    $certEcommerce = null;
    $stmt = $db->prepare("
        SELECT pc.id, pc.publication_id, pc.price, p.title as publication_title
        FROM publication_certificates pc
        JOIN publications p ON pc.publication_id = p.id
        WHERE pc.id = ?
    ");
    $stmt->execute([$certificateId]);
    $certData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($certData) {
        $certEcommerce = [
            'id' => 'pub-' . $certData['publication_id'],
            'name' => $certData['publication_title'],
            'price' => $certData['price'] ?? 299,
            'category' => 'Публикации'
        ];
    }

    if (removeCertificateFromCart($certificateId)) {
        echo json_encode([
            'success' => true,
            'message' => 'Свидетельство удалено из корзины',
            'cart_count' => getCartCount(),
            'ecommerce' => $certEcommerce
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Свидетельство не найдено в корзине'
        ]);
    }
    exit;
}

// Handle registration removal
if (!$registrationId) {
    echo json_encode([
        'success' => false,
        'message' => 'ID не указан'
    ]);
    exit;
}

// Получить данные конкурса для e-commerce перед удалением
$ecommerceData = null;
$stmt = $db->prepare("
    SELECT c.id, c.title, c.price, c.category, r.nomination
    FROM registrations r
    JOIN competitions c ON r.competition_id = c.id
    WHERE r.id = ?
");
$stmt->execute([$registrationId]);
$itemData = $stmt->fetch(PDO::FETCH_ASSOC);

if ($itemData) {
    $ecommerceData = [
        'id' => $itemData['id'],
        'name' => $itemData['title'],
        'price' => $itemData['price'],
        'category' => 'Конкурсы',
        'nomination' => $itemData['nomination']
    ];
}

// Remove from cart
if (removeFromCart($registrationId)) {
    echo json_encode([
        'success' => true,
        'message' => 'Конкурс удален из корзины',
        'cart_count' => getCartCount(),
        'ecommerce' => $ecommerceData
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Конкурс не найден в корзине'
    ]);
}

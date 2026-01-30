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

// Handle certificate removal
if ($certificateId) {
    if (removeCertificateFromCart($certificateId)) {
        echo json_encode([
            'success' => true,
            'message' => 'Свидетельство удалено из корзины',
            'cart_count' => getCartCount()
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
        'category' => $itemData['category'],
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

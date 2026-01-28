<?php
/**
 * Remove Item from Cart AJAX Endpoint
 * Removes registration from session cart
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';

// Get registration ID
$registrationId = $_POST['registration_id'] ?? null;

if (!$registrationId) {
    echo json_encode([
        'success' => false,
        'message' => 'ID регистрации не указан'
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

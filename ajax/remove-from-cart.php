<?php
/**
 * Remove Item from Cart AJAX Endpoint
 * Removes registration from session cart
 */

session_start();
header('Content-Type: application/json');

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

// Remove from cart
if (removeFromCart($registrationId)) {
    echo json_encode([
        'success' => true,
        'message' => 'Конкурс удален из корзины',
        'cart_count' => getCartCount()
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Конкурс не найден в корзине'
    ]);
}

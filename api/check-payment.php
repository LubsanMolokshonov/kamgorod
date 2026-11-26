<?php
/**
 * Check Payment Status API
 * Used for polling payment status from success page
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/course-payment-link.php';

// Get order number from query string
if (!is_string($_GET['order_number'] ?? null)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Order number is required'
    ]);
    exit;
}

$orderNumber = $_GET['order_number'];

try {
    // Load order
    $orderObj = new Order($db);
    $order = $orderObj->getByOrderNumber($orderNumber);

    header('Cache-Control: private, no-store');
    header('Referrer-Policy: no-referrer');
    if (!$order || !canAccessPurchaseResource($db, 'order', (int)$order['id'], (int)$order['user_id'])) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Order not found'
        ]);
        exit;
    }

    // Окно счётчика обновляется автоматически: повторный визит после задержки банка работает.
    $poll = $_SESSION['order_status_poll'][(int)$order['id']] ?? ['start' => 0, 'count' => 0];
    if (time() - $poll['start'] >= 60) $poll = ['start' => time(), 'count' => 0];
    if (++$poll['count'] > 30) {
        http_response_code(429);
        header('Retry-After: 60');
        echo json_encode(['success' => false, 'error' => 'Попробуйте через минуту']);
        exit;
    }
    $_SESSION['order_status_poll'][(int)$order['id']] = $poll;

    // Return order status
    echo json_encode([
        'success' => true,
        'status' => $order['payment_status'],
        'order_number' => $order['order_number'],
        'amount' => (float)$order['final_amount']
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Internal server error'
    ]);

    // Log error
    error_log('Check payment error: ' . $e->getMessage());
}

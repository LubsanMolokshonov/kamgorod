<?php
/**
 * Check Payment Status API
 * Used for polling payment status from success page
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';

// Get order number from query string
if (!isset($_GET['order_number'])) {
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

    if (!$order) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Order not found'
        ]);
        exit;
    }

    // Simple rate limiting: log request and check frequency
    // This prevents abuse of the polling endpoint
    $rateLimitKey = 'poll_' . $orderNumber;
    $cacheFile = sys_get_temp_dir() . '/' . md5($rateLimitKey) . '.txt';

    if (file_exists($cacheFile)) {
        $data = json_decode(file_get_contents($cacheFile), true);
        $requestCount = $data['count'] ?? 0;
        $firstRequest = $data['first'] ?? time();

        // Max 30 requests per order (generous limit)
        if ($requestCount > 30) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'error' => 'Rate limit exceeded'
            ]);
            exit;
        }

        // Update count
        file_put_contents($cacheFile, json_encode([
            'count' => $requestCount + 1,
            'first' => $firstRequest,
            'last' => time()
        ]));
    } else {
        // First request
        file_put_contents($cacheFile, json_encode([
            'count' => 1,
            'first' => time(),
            'last' => time()
        ]));
    }

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

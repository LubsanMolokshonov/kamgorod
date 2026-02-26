<?php
/**
 * Get E-commerce Data API
 * Returns ecommerce product data for deferred purchase tracking.
 * Used when user left payment-success page before purchase event fired.
 * Only returns data for succeeded orders belonging to the current user.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/session.php';

$orderNumber = $_GET['order_number'] ?? '';

if (empty($orderNumber)) {
    echo json_encode(['success' => false, 'error' => 'Missing order_number']);
    exit;
}

// Require logged-in user
$userId = getUserId();
if (!$userId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}

try {
    $orderObj = new Order($db);
    $order = $orderObj->getByOrderNumber($orderNumber);

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    // Security: order must belong to current user
    if ((int)$order['user_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'error' => 'Access denied']);
        exit;
    }

    // Only return data for succeeded orders
    if ($order['payment_status'] !== 'succeeded') {
        echo json_encode(['success' => false, 'error' => 'Order not succeeded']);
        exit;
    }

    // Build ecommerce products array (same logic as payment-success.php)
    $products = [];
    foreach ($order['items'] as $item) {
        if (!empty($item['webinar_certificate_id'])) {
            $products[] = [
                'id' => 'wc-' . $item['webinar_id'],
                'name' => $item['webinar_title'],
                'price' => $item['is_free_promotion'] ? 0 : (float)($item['webinar_cert_price'] ?? $item['price']),
                'brand' => 'Педпортал',
                'category' => 'Вебинары',
                'quantity' => 1
            ];
        } elseif (!empty($item['certificate_id'])) {
            $products[] = [
                'id' => 'pub-' . $item['publication_id'],
                'name' => $item['publication_title'] ?? '',
                'price' => $item['is_free_promotion'] ? 0 : (float)($item['price'] ?? 299),
                'brand' => 'Педпортал',
                'category' => 'Публикации',
                'quantity' => 1
            ];
        } elseif (!empty($item['registration_id'])) {
            $products[] = [
                'id' => (string)($item['competition_id'] ?? ''),
                'name' => $item['competition_title'] ?? '',
                'price' => $item['is_free_promotion'] ? 0 : (float)$item['price'],
                'brand' => 'Педпортал',
                'category' => 'Конкурсы',
                'variant' => $item['nomination'] ?? '',
                'quantity' => 1
            ];
        }
    }

    $actionField = [
        'id' => $order['order_number'],
        'revenue' => (float)$order['final_amount']
    ];
    if ($order['discount_amount'] > 0) {
        $actionField['coupon'] = '2+1';
    }

    echo json_encode([
        'success' => true,
        'ecommerce' => [
            'currencyCode' => 'RUB',
            'purchase' => [
                'actionField' => $actionField,
                'products' => $products
            ]
        ]
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('Get ecommerce data error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Internal error']);
}

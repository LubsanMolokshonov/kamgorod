<?php
/**
 * Create Payment AJAX Endpoint
 * Yookassa payment integration
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../vendor/autoload.php';

use YooKassa\Client;

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

try {
    // Initialize classes
    $registrationObj = new Registration($db);
    $userObj = new User($db);
    $orderObj = new Order($db);

    // Calculate cart total
    $cartData = $registrationObj->calculateCartTotal($_SESSION['cart']);

    if (empty($cartData['items'])) {
        throw new Exception('Корзина пуста или содержит недействительные позиции');
    }

    // Get user info from the first registration
    $userId = null;
    $userEmail = null;
    $firstRegId = $_SESSION['cart'][0];

    $stmt = $db->prepare("
        SELECT u.id, u.email, u.full_name
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        WHERE r.id = ?
    ");
    $stmt->execute([$firstRegId]);
    $userResult = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$userResult) {
        throw new Exception('Пользователь не найден');
    }

    $userId = $userResult['id'];
    $userEmail = $userResult['email'];
    $userName = $userResult['full_name'];

    // BEGIN TRANSACTION
    $db->beginTransaction();

    // Create order
    $orderId = $orderObj->createFromCart($userId, $cartData);

    if (!$orderId) {
        throw new Exception('Не удалось создать заказ');
    }

    // Get order details
    $order = $orderObj->getById($orderId);
    $orderNumber = $order['order_number'];

    // Initialize Yookassa client
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    // Prepare receipt items for 54-ФЗ compliance
    $receiptItems = [];
    foreach ($cartData['items'] as $item) {
        $receiptItems[] = [
            'description' => $item['competition_name'],
            'quantity' => 1,
            'amount' => [
                'value' => number_format($item['price'], 2, '.', ''),
                'currency' => 'RUB',
            ],
            'vat_code' => 1, // НДС не облагается
            'payment_mode' => 'full_payment',
            'payment_subject' => 'service',
        ];
    }

    // Prepare payment request
    $payment = $client->createPayment(
        [
            'amount' => [
                'value' => number_format($cartData['total'], 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => SITE_URL . '/pages/payment-success.php?order_number=' . $orderNumber,
            ],
            'capture' => true,
            'description' => 'Оплата заказа ' . $orderNumber,
            'receipt' => [
                'customer' => [
                    'email' => $userEmail,
                ],
                'items' => $receiptItems,
            ],
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
                'user_id' => $userId,
                'user_email' => $userEmail,
            ],
        ],
        $orderNumber // Idempotency key
    );

    // Get payment ID and confirmation URL
    $paymentId = $payment->getId();
    $confirmationUrl = $payment->getConfirmation()->getConfirmationUrl();

    // Update order with Yookassa details
    $orderObj->updateYookassaDetails($orderId, $paymentId, $confirmationUrl);

    // COMMIT TRANSACTION
    $db->commit();

    // Log success
    logPayment('CREATE', $orderNumber, $paymentId, 'Payment created successfully', $cartData['total']);

    // Set session user info
    $_SESSION['user_email'] = $userEmail;
    $_SESSION['user_id'] = $userId;

    // Return success with redirect URL
    echo json_encode([
        'success' => true,
        'message' => 'Перенаправление на страницу оплаты...',
        'redirect_url' => $confirmationUrl,
        'order_number' => $orderNumber,
        'payment_id' => $paymentId
    ]);

} catch (\YooKassa\Common\Exceptions\ApiException $e) {
    // Yookassa API error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Yookassa API error: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при создании платежа. Пожалуйста, попробуйте позже или свяжитесь с поддержкой.'
    ]);

} catch (\YooKassa\Common\Exceptions\BadApiRequestException $e) {
    // Bad request error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Bad API request: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Некорректный запрос к платежной системе. Проверьте данные заказа.'
    ]);

} catch (\YooKassa\Common\Exceptions\ForbiddenException $e) {
    // Forbidden error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Forbidden: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Доступ к платежной системе запрещен. Обратитесь к администратору.'
    ]);

} catch (\YooKassa\Common\Exceptions\UnauthorizedException $e) {
    // Unauthorized error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Unauthorized: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка авторизации в платежной системе. Обратитесь к администратору.'
    ]);

} catch (Exception $e) {
    // General error
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }

    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'General error: ' . $e->getMessage(), 0);

    echo json_encode([
        'success' => false,
        'message' => 'Ошибка при обработке заказа: ' . $e->getMessage()
    ]);
}

/**
 * Log payment operations
 */
function logPayment($level, $orderNumber, $paymentId, $message, $amount) {
    $logFile = BASE_PATH . '/logs/payment.log';
    $timestamp = date('Y-m-d H:i:s');
    $paymentIdStr = $paymentId ? $paymentId : 'N/A';
    $logMessage = "[{$timestamp}] {$level} | Order: {$orderNumber} | Payment ID: {$paymentIdStr} | Amount: {$amount} RUB | {$message}\n";

    // Ensure logs directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}

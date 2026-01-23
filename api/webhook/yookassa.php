<?php
/**
 * Yookassa Webhook Handler
 * Processes payment notifications from Yookassa
 */

// Enable error reporting
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Custom error handler to catch all errors
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $logFile = __DIR__ . '/../../logs/webhook.log';
    $message = "[" . date('Y-m-d H:i:s') . "] PHP_ERROR | {$errstr} in {$errfile}:{$errline}\n";
    error_log($message, 3, $logFile);
});

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logFile = __DIR__ . '/../../logs/webhook.log';
        $message = "[" . date('Y-m-d H:i:s') . "] FATAL_ERROR | {$error['message']} in {$error['file']}:{$error['line']}\n";
        error_log($message, 3, $logFile);
        http_response_code(200); // Always return 200 to prevent Yookassa retries
        echo json_encode(['status' => 'error', 'message' => 'Internal error']);
    }
});

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/Registration.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use YooKassa\Model\Notification\NotificationFactory;
use YooKassa\Client;

// Get raw POST data
$requestBody = file_get_contents('php://input');

// Log webhook received
logWebhook('INFO', 'N/A', 'Webhook received', $requestBody);

try {
    // Initialize Yookassa client for IP verification
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    // Verify IP address (handle proxies and Docker)
    $clientIp = $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';

    // If X-Forwarded-For contains multiple IPs, get the first one
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    if (!$client->isNotificationIPTrusted($clientIp)) {
        logWebhook('ERROR', 'N/A', 'Untrusted IP: ' . $clientIp, '');
        http_response_code(403);
        echo json_encode(['error' => 'Forbidden']);
        exit;
    }

    // Parse notification
    $factory = new NotificationFactory();
    $notificationData = json_decode($requestBody, true);
    $notification = $factory->factory($notificationData);
    $payment = $notification->getObject();

    $paymentId = $payment->getId();
    $paymentStatus = $payment->getStatus();
    $eventType = $notification->getEvent();

    logWebhook('INFO', $paymentId, "Event: {$eventType}, Status: {$paymentStatus}", '');

    // Initialize classes
    $orderObj = new Order($GLOBALS['db']);
    $registrationObj = new Registration($GLOBALS['db']);

    // Find order by payment ID
    $order = $orderObj->getByPaymentId($paymentId);

    if (!$order) {
        logWebhook('WARNING', $paymentId, 'Order not found', '');
        http_response_code(200); // Always return 200 to prevent retries
        echo json_encode(['status' => 'order_not_found']);
        exit;
    }

    $orderId = $order['id'];
    $orderNumber = $order['order_number'];
    $userId = $order['user_id'];

    // Check idempotency - prevent duplicate processing
    if ($orderObj->isProcessed($paymentId)) {
        logWebhook('INFO', $paymentId, "Order {$orderNumber} already processed", '');
        http_response_code(200);
        echo json_encode(['status' => 'already_processed']);
        exit;
    }

    // Handle different event types
    switch ($eventType) {
        case 'payment.succeeded':
            handlePaymentSucceeded($orderObj, $registrationObj, $order, $payment);
            break;

        case 'payment.canceled':
            handlePaymentCanceled($orderObj, $order, $payment);
            break;

        case 'payment.waiting_for_capture':
            handlePaymentWaitingForCapture($orderObj, $order, $payment);
            break;

        case 'refund.succeeded':
            handleRefundSucceeded($orderObj, $order, $payment);
            break;

        default:
            logWebhook('INFO', $paymentId, "Unhandled event type: {$eventType}", '');
    }

    // Always return 200 to Yookassa
    http_response_code(200);
    echo json_encode(['status' => 'ok']);

} catch (Exception $e) {
    // Log error but still return 200 to prevent Yookassa retries on our errors
    logWebhook('ERROR', $paymentId ?? 'unknown', 'Exception: ' . $e->getMessage(), $e->getTraceAsString());

    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
}

/**
 * Handle successful payment
 */
function handlePaymentSucceeded($orderObj, $registrationObj, $order, $payment) {
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = $order['id'];
    $userId = $order['user_id'];

    try {
        // BEGIN TRANSACTION
        $GLOBALS['db']->beginTransaction();

        // Update order status
        $paidAt = date('Y-m-d H:i:s');
        $orderObj->updatePaymentStatus($orderId, 'succeeded', $paidAt);

        // Mark all registrations as paid
        $orderObj->markRegistrationsAsPaid($orderId);

        // COMMIT TRANSACTION
        $GLOBALS['db']->commit();

        logWebhook('SUCCESS', $paymentId, "Order {$orderNumber} marked as succeeded", '');

        // Send success email (non-blocking)
        try {
            sendPaymentSuccessEmail($userId, $orderId);
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Email failed for order {$orderNumber}: " . $e->getMessage(), '');
            // Don't fail the webhook if email fails
        }

    } catch (Exception $e) {
        if ($GLOBALS['db']->inTransaction()) {
            $GLOBALS['db']->rollBack();
        }

        logWebhook('ERROR', $paymentId, "Failed to process succeeded payment: " . $e->getMessage(), '');
        throw $e;
    }
}

/**
 * Handle canceled payment
 */
function handlePaymentCanceled($orderObj, $order, $payment) {
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = $order['id'];

    try {
        // Update order status to failed
        $orderObj->updatePaymentStatus($orderId, 'failed');

        logWebhook('INFO', $paymentId, "Order {$orderNumber} marked as failed (canceled)", '');

        // Note: Registrations remain 'pending' so user can retry payment

    } catch (Exception $e) {
        logWebhook('ERROR', $paymentId, "Failed to process canceled payment: " . $e->getMessage(), '');
        throw $e;
    }
}

/**
 * Handle payment waiting for capture
 */
function handlePaymentWaitingForCapture($orderObj, $order, $payment) {
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = $order['id'];

    try {
        // Update order status to processing
        $orderObj->updatePaymentStatus($orderId, 'processing');

        logWebhook('INFO', $paymentId, "Order {$orderNumber} marked as processing (waiting for capture)", '');

    } catch (Exception $e) {
        logWebhook('ERROR', $paymentId, "Failed to process waiting for capture: " . $e->getMessage(), '');
        throw $e;
    }
}

/**
 * Handle successful refund
 */
function handleRefundSucceeded($orderObj, $order, $payment) {
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = $order['id'];
    $userId = $order['user_id'];

    try {
        // BEGIN TRANSACTION
        $GLOBALS['db']->beginTransaction();

        // Update order status to refunded
        $orderObj->updatePaymentStatus($orderId, 'refunded');

        // Mark registrations back to pending
        $stmt = $GLOBALS['db']->prepare("
            UPDATE registrations r
            INNER JOIN order_items oi ON r.id = oi.registration_id
            SET r.status = 'pending'
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$orderId]);

        // COMMIT TRANSACTION
        $GLOBALS['db']->commit();

        logWebhook('INFO', $paymentId, "Order {$orderNumber} refunded, registrations reset to pending", '');

    } catch (Exception $e) {
        if ($GLOBALS['db']->inTransaction()) {
            $GLOBALS['db']->rollBack();
        }

        logWebhook('ERROR', $paymentId, "Failed to process refund: " . $e->getMessage(), '');
        throw $e;
    }
}

/**
 * Log webhook events
 */
function logWebhook($level, $paymentId, $message, $details = '') {
    $logFile = BASE_PATH . '/logs/webhook.log';
    $timestamp = date('Y-m-d H:i:s');

    $logMessage = "[{$timestamp}] {$level} | Payment ID: {$paymentId} | {$message}";

    if ($details && strlen($details) < 500) {
        $logMessage .= " | Details: {$details}";
    }

    $logMessage .= "\n";

    // Ensure logs directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}

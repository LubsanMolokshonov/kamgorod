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
        $retryEvent = in_array($GLOBALS['eventType'] ?? '', ['refund.succeeded', 'payment.succeeded', 'payment.waiting_for_capture', 'payment.canceled'], true)
            || !empty($GLOBALS['coursePaymentEvent']) || !empty($GLOBALS['cartPaymentEvent']);
        http_response_code($retryEvent ? 503 : 200);
        echo json_encode(['status' => 'error', 'message' => 'Internal error']);
    }
});

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Order.php';
require_once __DIR__ . '/../../classes/CoursePaymentEvent.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/paid-order.php';
require_once __DIR__ . '/../../classes/TelegramNotifier.php';
require_once __DIR__ . '/../../vendor/autoload.php';

use YooKassa\Model\Notification\NotificationFactory;
use YooKassa\Client;

// Алерт в Telegram на фатал вебхука (класс не найден, parse error и т.п.). Раньше фатал
// уходил только в logs/webhook.log и оставался незамеченным — так баг неймспейса
// PaymentStatus 4 дня молча ломал активацию подписок. Свой shutdown-handler выше
// (логирование в файл + ответ 200) остаётся; этот — добавляет оповещение.
TelegramNotifier::registerFatalHandler('yookassa-webhook');

// Get raw POST data
$requestBody = file_get_contents('php://input');

// Log webhook received
logWebhook('INFO', 'N/A', 'Webhook received', '');

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

    // Возврат — отдельный объект, в том числе для токенов без заказа.
    // Проверяем его и накопленную сумму через API до любых изменений.
    if ($eventType === 'refund.succeeded') {
        require_once __DIR__ . '/../../classes/PaymentRefundAccounting.php';
        $refundResult = (new PaymentRefundAccounting($GLOBALS['db'], $client))->syncRefund($paymentId);
        $orderObj = new Order($GLOBALS['db']);
        if ($refundResult['full']) {
            $originalPaymentId = $refundResult['payment_id'];
            $order = $orderObj->getByPaymentId($originalPaymentId) ?: null;
            $enrollmentId = CoursePaymentEvent::enrollmentForOrder($order);
            $verified = null;
            if (!$order && !$refundResult['linked']) {
                $verified = $client->getPaymentInfo($originalPaymentId);
                if ($verified->getId() === $originalPaymentId) {
                    $enrollmentId = CoursePaymentEvent::enrollmentForAttempt($GLOBALS['db'], CoursePaymentEvent::attemptKey($verified));
                    if (($verified->getMetadata()['payment_type'] ?? '') === 'subscription') {
                        require_once __DIR__ . '/../../classes/SubscriptionPayments.php';
                        (new SubscriptionPayments($GLOBALS['db'], $client))->accept($verified);
                        $order = $orderObj->getByPaymentId($originalPaymentId) ?: null;
                    }
                    if (!$enrollmentId && ($verified->getMetadata()['payment_type'] ?? '') === 'cart') {
                        $orderObj->attachVerifiedCartPayment($verified);
                        $order = $orderObj->getByPaymentId($originalPaymentId) ?: null;
                    }
                }
            }
            if ($enrollmentId > 0) {
                require_once BASE_PATH . '/classes/CourseCheckout.php';
                CourseCheckout::withEnrollmentLock($GLOBALS['db'], $enrollmentId, static function () use (
                    $orderObj, $payment, $verified, $enrollmentId, $originalPaymentId
                ): void {
                    $current = $orderObj->getByPaymentId($originalPaymentId);
                    if (!$current && $verified && CoursePaymentEvent::attachVerified($GLOBALS['db'], $enrollmentId, $verified)) {
                        $current = $orderObj->getByPaymentId($originalPaymentId);
                    }
                    if ($current && $current['payment_status'] !== 'refunded') {
                        handleRefundSucceeded($orderObj, $current, $payment);
                    }
                });
            } elseif ($order && $order['payment_status'] !== 'refunded') {
                handleRefundSucceeded($orderObj, $order, $payment);
            }
        }
        logWebhook('INFO', $paymentId, 'Refund accounted: ' . $refundResult['refunded_amount'], '');
        http_response_code(200);
        echo json_encode(['status' => 'refund_accounted']);
        exit;
    }

    $payment = $client->getPaymentInfo($paymentId);
    if (!$payment || $payment->getId() !== $paymentId) {
        throw new RuntimeException('Не удалось подтвердить идентификатор платежа');
    }
    $paymentStatus = $payment->getStatus();
    $eventType = 'payment.' . $paymentStatus;

    // ============================================================
    // Покупка токенов для генератора материалов ФОП
    // metadata.payment_type='tokens' — обрабатываем отдельной веткой,
    // без Order/order_items. Идемпотентность — по token_transactions.payment_id.
    // ============================================================
    $metadata = method_exists($payment, 'getMetadata') ? ($payment->getMetadata() ?: null) : null;
    $metaArray = $metadata ? (is_array($metadata) ? $metadata : (method_exists($metadata, 'toArray') ? $metadata->toArray() : (array)$metadata)) : [];

    if (($metaArray['payment_type'] ?? null) === 'tokens') {
        require_once __DIR__ . '/../../classes/UserTokens.php';
        require_once __DIR__ . '/../../classes/TokenPackage.php';

        if ($paymentStatus !== \YooKassa\Model\PaymentStatus::SUCCEEDED) {
            logWebhook('INFO', $paymentId, "Tokens payment status={$paymentStatus} — wait for SUCCEEDED", '');
            http_response_code(200);
            echo json_encode(['status' => 'tokens_waiting']);
            exit;
        }

        if (PaymentRefundAccounting::isFullyRefunded($payment)) {
            http_response_code(200);
            echo json_encode(['status' => 'tokens_refunded']);
            exit;
        }
        $tokensUserId  = (int)($metaArray['user_id']   ?? 0);
        $tokensPackage = (int)($metaArray['package_id'] ?? 0);
        if ($tokensUserId <= 0 || $tokensPackage <= 0) {
            logWebhook('ERROR', $paymentId, 'Tokens payment without user_id/package_id metadata', json_encode($metaArray));
            http_response_code(200);
            echo json_encode(['status' => 'tokens_bad_metadata']);
            exit;
        }

        // Идемпотентность: по payment_id + reason='purchase'
        $alreadyCredited = (new Database($GLOBALS['db']))->queryOne(
            "SELECT id FROM token_transactions WHERE payment_id = ? AND reason = 'purchase' LIMIT 1",
            [$paymentId]
        );
        if ($alreadyCredited) {
            logWebhook('INFO', $paymentId, "Tokens already credited for payment {$paymentId}", '');
            http_response_code(200);
            echo json_encode(['status' => 'tokens_already_credited']);
            exit;
        }

        $packageObj = new TokenPackage($GLOBALS['db']);
        $package = $packageObj->getById($tokensPackage);
        if (!$package) {
            logWebhook('ERROR', $paymentId, "Tokens package #{$tokensPackage} not found", '');
            http_response_code(200);
            echo json_encode(['status' => 'tokens_package_not_found']);
            exit;
        }

        $totalTokens = $packageObj->totalTokens($package);
        $amountRub = (float)$payment->getAmount()->getValue();
        $tokens = new UserTokens($GLOBALS['db']);
        try {
            $txnId = $tokens->credit($tokensUserId, $totalTokens, 'purchase', [
                'package_id' => $tokensPackage,
                'payment_id' => $paymentId,
                'notes' => 'Yookassa ' . $payment->getAmount()->getValue() . ' ' . $payment->getAmount()->getCurrency(),
                // Сумма и UTM для атрибуции выручки ФОП в РНП (миграция 140).
                'amount_paid' => $amountRub,
                'utm_source'   => $metaArray['utm_source']   ?? null,
                'utm_medium'   => $metaArray['utm_medium']   ?? null,
                'utm_campaign' => $metaArray['utm_campaign'] ?? null,
                'utm_content'  => $metaArray['utm_content']  ?? null,
                'utm_term'     => $metaArray['utm_term']     ?? null,
            ]);
            logWebhook('INFO', $paymentId, "Tokens credited: user={$tokensUserId} amount={$totalTokens} package={$tokensPackage} txn={$txnId}", '');
        } catch (Throwable $e) {
            logWebhook('ERROR', $paymentId, 'Tokens credit failed: ' . $e->getMessage(), '');
            http_response_code(200);
            echo json_encode(['status' => 'tokens_credit_error']);
            exit;
        }

        // Транзакционное письмо о покупке + гашение pending балансовых писем (non-fatal)
        try {
            require_once __DIR__ . '/../../classes/MaterialTokenEmailChain.php';
            $matChain = new MaterialTokenEmailChain($GLOBALS['db']);
            $matChain->sendPurchaseConfirmation($tokensUserId, $package, $totalTokens, $paymentId);
            $matChain->cancelPendingForUser($tokensUserId, ['balance']);
        } catch (Throwable $e) {
            logWebhook('WARNING', $paymentId, 'Material purchase email failed (non-fatal): ' . $e->getMessage(), '');
        }

        // Bitrix24: оплаченная сделка в воронке «Курсы» (CATEGORY_ID=108)
        try {
            if (defined('BITRIX24_WEBHOOK_URL') && BITRIX24_WEBHOOK_URL) {
                require_once __DIR__ . '/../../classes/Bitrix24Integration.php';
                $userRow = (new Database($GLOBALS['db']))->queryOne(
                    "SELECT email, full_name, phone FROM users WHERE id = ?",
                    [$tokensUserId]
                );
                $bitrix = new Bitrix24Integration();
                $dealId = $bitrix->createDeal([
                    'TITLE' => 'Покупка токенов «' . $package['name'] . '» (' . $totalTokens . ')',
                    'CATEGORY_ID' => 108,
                    'STAGE_ID' => 'C108:WON',
                    'OPPORTUNITY' => $amountRub,
                    'CURRENCY_ID' => 'RUB',
                    'COMMENTS' => "Покупка пакета токенов на fgos.pro\n"
                                . "User: #{$tokensUserId}, email: " . ($userRow['email'] ?? '—') . "\n"
                                . "Tokens: {$totalTokens}\n"
                                . "Yookassa payment: {$paymentId}",
                    'UF_CRM_USER_EMAIL' => $userRow['email'] ?? '',
                ]);
                if ($dealId) {
                    logWebhook('INFO', $paymentId, "Bitrix24 token deal created: #{$dealId}", '');
                }
            }
        } catch (Throwable $e) {
            logWebhook('WARNING', $paymentId, 'Bitrix24 deal creation failed (non-fatal): ' . $e->getMessage(), '');
        }

        http_response_code(200);
        echo json_encode(['status' => 'tokens_credited', 'tokens' => $totalTokens]);
        exit;
    }

    // ============================================================
    // Оплата ПОДПИСКИ (Базовый / Про).
    // metadata.payment_type='subscription'. Заказ создан в orders с
    // subscription_plan_id, без order_items. Идемпотентность — Order::isProcessed.
    // ============================================================
    if (($metaArray['payment_type'] ?? null) === 'subscription') {
        require_once __DIR__ . '/../../classes/SubscriptionPayments.php';
        $subscriptionEvent = true;
        $result = (new SubscriptionPayments($GLOBALS['db'], $client))->accept($payment);
        http_response_code(200);
        echo json_encode(['status' => $result['status'], 'subscription_id' => $result['id']]);
        exit;
    }

    // Initialize classes
    $orderObj = new Order($GLOBALS['db']);

    $order = $orderObj->getByPaymentId($paymentId) ?: null;
    $cartPaymentEvent = ($metaArray['payment_type'] ?? '') === 'cart';
    if (!$order && $cartPaymentEvent) {
        $orderObj->attachVerifiedCartPayment($payment);
        $order = $orderObj->getByPaymentId($paymentId) ?: null;
        if (!$order) {
            throw new RuntimeException('Не удалось связать платёж с заказом корзины');
        }
    }
    $courseEnrollmentId = CoursePaymentEvent::enrollmentForOrder($order);
    $attemptKey = CoursePaymentEvent::attemptKey($payment);
    $coursePaymentEvent = $courseEnrollmentId > 0 || $attemptKey !== '';
    if (!$order && $attemptKey !== '') {
        $courseEnrollmentId = CoursePaymentEvent::enrollmentForAttempt($GLOBALS['db'], $attemptKey);
    }

    $processEvent = static function (?array $currentOrder) use ($orderObj, $payment, $paymentId, $eventType): string {
        if (!$currentOrder) {
            logWebhook('WARNING', $paymentId, 'Order not found for current payment', '');
            return 'order_not_found';
        }
        if (in_array($currentOrder['payment_status'], ['succeeded', 'refunded'], true)) {
            return 'already_processed';
        }
        switch ($eventType) {
            case 'payment.succeeded':
                settlePaidOrder($GLOBALS['db'], $currentOrder, $payment, 'webhook',
                    static fn(string $level, string $msg) => logWebhook($level, $paymentId, $msg, ''));
                break;

            case 'payment.canceled':
                handlePaymentCanceled($orderObj, $currentOrder, $payment);
                break;

            case 'payment.waiting_for_capture':
                handlePaymentWaitingForCapture($orderObj, $currentOrder, $payment);
                break;

            default:
                logWebhook('INFO', $paymentId, "Unhandled event type: {$eventType}", '');
        }
        return 'ok';
    };

    if ($courseEnrollmentId > 0) {
        require_once BASE_PATH . '/classes/CourseCheckout.php';
        $status = CourseCheckout::withEnrollmentLock($GLOBALS['db'], $courseEnrollmentId, static function () use (
            $orderObj, $courseEnrollmentId, $payment, $paymentId, $attemptKey, $processEvent
        ): string {
            $currentOrder = $orderObj->getByPaymentId($paymentId) ?: null;
            if (!$currentOrder && CoursePaymentEvent::enrollmentForAttempt($GLOBALS['db'], $attemptKey) === $courseEnrollmentId) {
                // API, а не metadata входящего уведомления, подтверждает новый ID платежа.
                if (CoursePaymentEvent::attachVerified($GLOBALS['db'], $courseEnrollmentId, $payment)) {
                    $currentOrder = $orderObj->getByPaymentId($paymentId) ?: null;
                }
            }
            if ($currentOrder && !CoursePaymentEvent::matchesAmount($currentOrder, $payment)) {
                logWebhook('ERROR', $paymentId, 'Course payment amount does not match the order', '');
                return 'amount_mismatch';
            }
            return $processEvent($currentOrder);
        });
    } else {
        $status = $processEvent($order);
    }

    http_response_code(200);
    echo json_encode(['status' => $status]);

} catch (Throwable $e) {
    // Ловим Throwable (не только Exception): PHP-Error вроде «class not found» — это Error,
    // он мимо catch(Exception) улетал бы в фатал. logWebhook('ERROR') сам шлёт Telegram-алерт.
    // Ошибки возвратов требуют повтора: иначе финансовая корректировка потеряется.
    logWebhook('ERROR', $paymentId ?? 'unknown', 'Exception: ' . $e->getMessage(), $e->getTraceAsString());

    $retryEvent = in_array($eventType ?? '', ['refund.succeeded', 'payment.succeeded', 'payment.waiting_for_capture', 'payment.canceled'], true)
        || !empty($coursePaymentEvent) || !empty($cartPaymentEvent) || !empty($subscriptionEvent);
    http_response_code($retryEvent ? 503 : 200);
    echo json_encode(['status' => 'error']);
}

/**
 * Handle canceled payment
 */
function handlePaymentCanceled($orderObj, $order, $payment) {
    $paymentId = $payment->getId();
    $orderNumber = $order['order_number'];
    $orderId = $order['id'];

    try {
        if (!$orderObj->cancelYookassaPayment((int)$orderId, $paymentId)) {
            return;
        }

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
        if (!$orderObj->updateActiveYookassaStatus((int)$orderId, $paymentId, 'processing')) {
            return;
        }

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

    if (!empty($order['subscription_plan_id'])) {
        require_once __DIR__ . '/../../classes/SubscriptionPeriods.php';
        (new SubscriptionPeriods($GLOBALS['db']))->refund((int)$orderId, (int)$userId);
        return;
    }

    refundPurchaseOrder($GLOBALS['db'], (int)$orderId, (string)$order['yookassa_payment_id']);
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

    // Алертинг: любой ERROR/FATAL в вебхуке критичен (оплата прошла, а выдача/активация
    // сломалась). Шлём в Telegram. Throttling по тексту внутри TelegramNotifier (isDuplicate
    // по key). Алерт не должен ронять сам вебхук — глушим любые исключения.
    if (in_array($level, ['ERROR', 'FATAL'], true) && class_exists('TelegramNotifier')) {
        try {
            TelegramNotifier::instance($GLOBALS['db'] ?? null)->alert(
                'yookassa_webhook_error_' . substr(md5($message), 0, 10),
                '[Webhook] Yookassa: ' . $level,
                ['payment_id' => (string)$paymentId, 'message' => (string)$message],
                'critical'
            );
        } catch (\Throwable $e) {
            // no-op
        }
    }
}

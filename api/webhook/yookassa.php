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
require_once __DIR__ . '/../../classes/PublicationCertificate.php';
require_once __DIR__ . '/../../classes/WebinarCertificate.php';
require_once __DIR__ . '/../../classes/Diploma.php';
require_once __DIR__ . '/../../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../../classes/OlympiadDiploma.php';
require_once __DIR__ . '/../../classes/EmailJourney.php';
require_once __DIR__ . '/../../classes/PublicationEmailChain.php';
require_once __DIR__ . '/../../classes/User.php';
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

        // Mark all publication certificates as paid and generate them
        $certObj = new PublicationCertificate($GLOBALS['db']);
        $orderItems = $orderObj->getOrderItems($orderId);
        foreach ($orderItems as $item) {
            if (!empty($item['certificate_id'])) {
                $certObj->updateStatus($item['certificate_id'], 'paid');
                $certObj->generate($item['certificate_id']);
                logWebhook('INFO', $paymentId, "Certificate {$item['certificate_id']} generated for order {$orderNumber}", '');
            }
        }

        // Mark all webinar certificates as paid and generate them
        $webCertObj = new WebinarCertificate($GLOBALS['db']);
        foreach ($orderItems as $item) {
            if (!empty($item['webinar_certificate_id'])) {
                $webCertObj->updateStatus($item['webinar_certificate_id'], 'paid');
                $webCertObj->generate($item['webinar_certificate_id']);
                logWebhook('INFO', $paymentId, "Webinar certificate {$item['webinar_certificate_id']} generated for order {$orderNumber}", '');

                // Cancel autowebinar email chain for this registration
                try {
                    require_once BASE_PATH . '/classes/AutowebinarEmailChain.php';
                    $wcData = $webCertObj->getById($item['webinar_certificate_id']);
                    if ($wcData && !empty($wcData['registration_id'])) {
                        $awChain = new AutowebinarEmailChain($GLOBALS['db']);
                        $awChain->cancelForRegistration($wcData['registration_id']);
                        logWebhook('INFO', $paymentId, "Autowebinar email chain cancelled for registration {$wcData['registration_id']}", '');
                    }
                } catch (Exception $e) {
                    logWebhook('WARNING', $paymentId, "AW email cancel failed: " . $e->getMessage(), '');
                }
            }
        }

        // Generate diplomas for paid registrations
        $diplomaObj = new Diploma($GLOBALS['db']);
        foreach ($orderItems as $item) {
            if (!empty($item['registration_id'])) {
                $result = $diplomaObj->generate($item['registration_id'], 'participant');
                if ($result['success']) {
                    logWebhook('INFO', $paymentId, "Diploma (participant) generated for registration {$item['registration_id']}", '');
                }
                // Generate supervisor diploma if supervisor exists
                $regData = $diplomaObj->getRegistrationData($item['registration_id']);
                if ($regData && !empty($regData['has_supervisor']) && !empty($regData['supervisor_name'])) {
                    $supResult = $diplomaObj->generate($item['registration_id'], 'supervisor');
                    if ($supResult['success']) {
                        logWebhook('INFO', $paymentId, "Diploma (supervisor) generated for registration {$item['registration_id']}", '');
                    }
                }
            }
        }

        // Mark olympiad registrations as paid and generate olympiad diplomas
        $olympRegObj = new OlympiadRegistration($GLOBALS['db']);
        $olympDiplomaObj = new OlympiadDiploma($GLOBALS['db']);
        foreach ($orderItems as $item) {
            if (!empty($item['olympiad_registration_id'])) {
                $olympRegObj->update($item['olympiad_registration_id'], ['status' => 'paid']);
                logWebhook('INFO', $paymentId, "Olympiad registration {$item['olympiad_registration_id']} marked as paid", '');

                // Generate participant diploma
                $result = $olympDiplomaObj->generate($item['olympiad_registration_id'], 'participant');
                if ($result['success']) {
                    logWebhook('INFO', $paymentId, "Olympiad diploma (participant) generated for reg {$item['olympiad_registration_id']}", '');
                }

                // Generate supervisor diploma if supervisor exists
                $olympReg = $olympRegObj->getById($item['olympiad_registration_id']);
                if ($olympReg && !empty($olympReg['has_supervisor']) && !empty($olympReg['supervisor_name'])) {
                    $supResult = $olympDiplomaObj->generate($item['olympiad_registration_id'], 'supervisor');
                    if ($supResult['success']) {
                        logWebhook('INFO', $paymentId, "Olympiad diploma (supervisor) generated for reg {$item['olympiad_registration_id']}", '');
                    }
                }
            }
        }

        // Mark course enrollments as paid
        foreach ($orderItems as $item) {
            if (!empty($item['course_enrollment_id'])) {
                $stmt = $GLOBALS['db']->prepare(
                    "UPDATE course_enrollments SET status = 'paid' WHERE id = ?"
                );
                $stmt->execute([$item['course_enrollment_id']]);
                logWebhook('INFO', $paymentId, "Course enrollment {$item['course_enrollment_id']} marked as paid", '');
            }
        }

        // COMMIT TRANSACTION
        $GLOBALS['db']->commit();

        logWebhook('SUCCESS', $paymentId, "Order {$orderNumber} marked as succeeded", '');

        // Sync user specializations from purchased events (additive)
        try {
            $specIds = $orderObj->getSpecializationIdsForOrder($orderId);
            if (!empty($specIds)) {
                $userObj = new User($GLOBALS['db']);
                $added = $userObj->addSpecializations($userId, $specIds);
                if ($added > 0) {
                    logWebhook('INFO', $paymentId, "Added {$added} specializations to user {$userId} from order {$orderNumber}", '');
                }
            }
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Specialization sync failed: " . $e->getMessage(), '');
        }

        // Bitrix24: создать/переместить сделку для оплаченных курсов
        try {
            require_once BASE_PATH . '/classes/Bitrix24Integration.php';
            require_once BASE_PATH . '/classes/Course.php';

            $bitrix = new Bitrix24Integration();
            if ($bitrix->isConfigured()) {
                $dbHelper = new Database($GLOBALS['db']);
                $courseObj = new Course($GLOBALS['db']);

                foreach ($orderItems as $item) {
                    if (empty($item['course_enrollment_id'])) continue;

                    try {
                        $GLOBALS['db']->beginTransaction();
                        $enrollment = $dbHelper->queryOne(
                            "SELECT * FROM course_enrollments WHERE id = ? FOR UPDATE",
                            [$item['course_enrollment_id']]
                        );

                        if (!$enrollment) {
                            $GLOBALS['db']->commit();
                            continue;
                        }

                        $paidStage = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:EXECUTING';

                        if (empty($enrollment['bitrix_lead_id'])) {
                            // Сделка ещё не создана — создаём с этапом "Оплата на сайте"
                            $course = $courseObj->getById($enrollment['course_id']);
                            if ($course) {
                                $dealId = $bitrix->createCourseDeal([
                                    'full_name' => $enrollment['full_name'],
                                    'email' => $enrollment['email'],
                                    'phone' => $enrollment['phone'],
                                    'utm_source' => $enrollment['utm_source'] ?? '',
                                    'utm_medium' => $enrollment['utm_medium'] ?? '',
                                    'utm_campaign' => $enrollment['utm_campaign'] ?? '',
                                    'utm_content' => $enrollment['utm_content'] ?? '',
                                    'utm_term' => $enrollment['utm_term'] ?? '',
                                    'ym_uid' => $enrollment['ym_uid'] ?? '',
                                    'source_page' => $enrollment['source_page'] ?? '',
                                ], $course, $paidStage);

                                if ($dealId) {
                                    $dbHelper->update('course_enrollments', [
                                        'bitrix_lead_id' => $dealId,
                                        'bitrix_stage' => $paidStage,
                                    ], 'id = ?', [$item['course_enrollment_id']]);
                                    logWebhook('INFO', $paymentId, "Bitrix24 course deal {$dealId} created (stage: {$paidStage}) for enrollment {$item['course_enrollment_id']}", '');
                                }
                            }
                        } else {
                            // Cron уже создал сделку — переместить на этап "Оплата на сайте"
                            $bitrix->moveDeal($enrollment['bitrix_lead_id'], $paidStage);
                            $dbHelper->update('course_enrollments', [
                                'bitrix_stage' => $paidStage,
                            ], 'id = ?', [$item['course_enrollment_id']]);
                            logWebhook('INFO', $paymentId, "Bitrix24 deal {$enrollment['bitrix_lead_id']} moved to {$paidStage} for enrollment {$item['course_enrollment_id']}", '');
                        }

                        $GLOBALS['db']->commit();
                    } catch (Exception $e) {
                        if ($GLOBALS['db']->inTransaction()) {
                            $GLOBALS['db']->rollBack();
                        }
                        logWebhook('WARNING', $paymentId, "Bitrix24 course deal error for enrollment {$item['course_enrollment_id']}: " . $e->getMessage(), '');
                    }
                }
            }
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Bitrix24 course integration error: " . $e->getMessage(), '');
        }

        // Cancel email journey for paid registrations
        try {
            $emailJourney = new EmailJourney($GLOBALS['db']);
            foreach ($orderItems as $item) {
                if (!empty($item['registration_id'])) {
                    $emailJourney->cancelForRegistration($item['registration_id']);
                }
            }
            logWebhook('INFO', $paymentId, "Email journey cancelled for order {$orderNumber}", '');
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Email journey cancel failed: " . $e->getMessage(), '');
        }

        // Cancel publication email chain for paid certificates
        try {
            $pubChain = new PublicationEmailChain($GLOBALS['db']);
            foreach ($orderItems as $item) {
                if (!empty($item['certificate_id'])) {
                    // Получить publication_id из сертификата
                    $certRow = $GLOBALS['db']->prepare("SELECT publication_id FROM publication_certificates WHERE id = ?");
                    $certRow->execute([$item['certificate_id']]);
                    $certData = $certRow->fetch(PDO::FETCH_ASSOC);
                    if ($certData) {
                        $pubChain->cancelForPublication($certData['publication_id']);
                    }
                }
            }
            logWebhook('INFO', $paymentId, "Publication email chain cancelled for order {$orderNumber}", '');
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Publication email chain cancel failed: " . $e->getMessage(), '');
        }

        // Cancel olympiad email chain for paid olympiad registrations
        try {
            require_once BASE_PATH . '/classes/OlympiadEmailChain.php';
            $olympiadChain = new OlympiadEmailChain($GLOBALS['db']);
            foreach ($orderItems as $item) {
                if (!empty($item['olympiad_registration_id'])) {
                    $olympiadChain->cancelForRegistration($item['olympiad_registration_id']);
                }
            }
            logWebhook('INFO', $paymentId, "Olympiad email chain cancelled for order {$orderNumber}", '');
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Olympiad email chain cancel failed: " . $e->getMessage(), '');
        }

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

        // Reset course enrollments back to 'new'
        $stmt = $GLOBALS['db']->prepare("
            UPDATE course_enrollments ce
            INNER JOIN order_items oi ON ce.id = oi.course_enrollment_id
            SET ce.status = 'new'
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

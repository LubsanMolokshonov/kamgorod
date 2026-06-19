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
require_once __DIR__ . '/../../classes/WebinarRegistration.php';
require_once __DIR__ . '/../../classes/Diploma.php';
require_once __DIR__ . '/../../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../../classes/OlympiadDiploma.php';
require_once __DIR__ . '/../../classes/EmailJourney.php';
require_once __DIR__ . '/../../classes/PublicationEmailChain.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../../classes/EmailCampaignDiscount.php';
require_once __DIR__ . '/../../includes/email-helper.php';
require_once __DIR__ . '/../../includes/session.php';
require_once __DIR__ . '/../../includes/order-fulfillment.php';
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

        if ($paymentStatus !== \YooKassa\Model\Payment\PaymentStatus::SUCCEEDED) {
            logWebhook('INFO', $paymentId, "Tokens payment status={$paymentStatus} — wait for SUCCEEDED", '');
            http_response_code(200);
            echo json_encode(['status' => 'tokens_waiting']);
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
        require_once __DIR__ . '/../../classes/SubscriptionService.php';

        if ($paymentStatus !== \YooKassa\Model\Payment\PaymentStatus::SUCCEEDED) {
            logWebhook('INFO', $paymentId, "Subscription payment status={$paymentStatus} — wait for SUCCEEDED", '');
            http_response_code(200);
            echo json_encode(['status' => 'subscription_waiting']);
            exit;
        }

        $orderObjSub = new Order($GLOBALS['db']);
        $order = $orderObjSub->getByPaymentId($paymentId);
        if (!$order) {
            logWebhook('WARNING', $paymentId, 'Subscription order not found', '');
            http_response_code(200);
            echo json_encode(['status' => 'order_not_found']);
            exit;
        }
        if ($orderObjSub->isProcessed($paymentId)) {
            logWebhook('INFO', $paymentId, "Subscription order {$order['order_number']} already processed", '');
            http_response_code(200);
            echo json_encode(['status' => 'already_processed']);
            exit;
        }

        $subUserId = (int)($metaArray['user_id'] ?? $order['user_id']);
        $planId    = (int)($metaArray['plan_id'] ?? $order['subscription_plan_id'] ?? 0);
        $period    = (string)($metaArray['period'] ?? $order['subscription_period'] ?? 'monthly');

        // Сохранённый метод оплаты (Этап 2: автопродление). На Этапе 1 остаётся null.
        $pmId = null;
        try {
            $pm = method_exists($payment, 'getPaymentMethod') ? $payment->getPaymentMethod() : null;
            if ($pm && method_exists($pm, 'getSaved') && $pm->getSaved()) {
                $pmId = $pm->getId();
            }
        } catch (Throwable $e) {
            $pmId = null;
        }

        try {
            $GLOBALS['db']->beginTransaction();
            $orderObjSub->updatePaymentStatus($order['id'], 'succeeded', date('Y-m-d H:i:s'));
            $subService = new SubscriptionService($GLOBALS['db']);
            $subId = $subService->activate($subUserId, $planId, $period, (int)$order['id'], $pmId);
            $GLOBALS['db']->commit();
            logWebhook('SUCCESS', $paymentId, "Subscription activated: user={$subUserId} plan={$planId} period={$period} sub={$subId}", '');
        } catch (Throwable $e) {
            if ($GLOBALS['db']->inTransaction()) {
                $GLOBALS['db']->rollBack();
            }
            logWebhook('ERROR', $paymentId, 'Subscription activate failed: ' . $e->getMessage(), '');
            http_response_code(200);
            echo json_encode(['status' => 'subscription_error']);
            exit;
        }

        // Письмо «подписка активна» (best-effort)
        try {
            if (function_exists('sendSubscriptionActivatedEmail')) {
                sendSubscriptionActivatedEmail($subUserId, $subId);
            }
        } catch (Throwable $e) {
            logWebhook('WARNING', $paymentId, 'Subscription activation email failed (non-fatal): ' . $e->getMessage(), '');
        }

        // Пожизненная скидка лояльности — оплата подписки тоже первый успешный платёж.
        try {
            if (LoyaltyDiscount::isFirstSuccessfulOrder($GLOBALS['db'], $subUserId, (int)$order['id'])) {
                $userObjLocal = new User($GLOBALS['db']);
                if ($userObjLocal->grantLifetimeDiscount($subUserId)) {
                    scheduleDelayedEmail('lifetime_discount_granted', $subUserId, (int)$order['id'], 10);
                }
            }
        } catch (Throwable $e) {
            logWebhook('WARNING', $paymentId, 'Subscription loyalty grant failed (non-fatal): ' . $e->getMessage(), '');
        }

        http_response_code(200);
        echo json_encode(['status' => 'subscription_activated', 'subscription_id' => $subId]);
        exit;
    }

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
        // Единый «движок выдачи»: статусы → PDF → отмена email-цепочек → проверка готовности.
        // Та же функция используется при оформлении подписчиком за 0 ₽ (create-payment.php),
        // что исключает расхождение логики выдачи документов.
        $fulfillResult = fulfillOrderItems(
            $GLOBALS['db'],
            (int)$orderId,
            'webhook',
            function (string $level, string $message) use ($paymentId): void {
                logWebhook($level, $paymentId, $message, '');
            }
        );
        $orderItems = $fulfillResult['order_items'];
        $allDocsReady = $fulfillResult['all_docs_ready'];

        // Email-атрибуция: связать оплату с конкретным письмом.
        // 1) Прямая привязка по orders.email_message_id (установлен на клике из письма).
        // 2) Fallback: если orders.utm_source='email', ищем последнее письмо этому user_id
        //    с таким же utm_campaign в окне EMAIL_ATTRIBUTION_WINDOW_DAYS.
        try {
            require_once BASE_PATH . '/classes/EmailTracker.php';
            $dbHelper = new Database($GLOBALS['db']);
            $orderRow = $dbHelper->queryOne(
                "SELECT id, user_id, final_amount, email_message_id, utm_source, utm_campaign
                   FROM orders WHERE id = ?",
                [$orderId]
            );
            if ($orderRow) {
                $mid = $orderRow['email_message_id'] ?? null;
                if (!$mid && strtolower((string)($orderRow['utm_source'] ?? '')) === 'email') {
                    $window = defined('EMAIL_ATTRIBUTION_WINDOW_DAYS') ? (int)EMAIL_ATTRIBUTION_WINDOW_DAYS : 7;
                    $mid = EmailTracker::findAttributionFallback(
                        (int)$orderRow['user_id'],
                        $orderRow['utm_campaign'] ?? null,
                        $window
                    );
                }
                if ($mid) {
                    EmailTracker::attributeConversion($mid, (int)$orderId, (float)$orderRow['final_amount']);
                    // Заказ пришёл по письму, но UTM не проставлены (клик из почтового
                    // клиента без UTM в ссылке, либо session/cookie не дожили до оплаты).
                    // Помечаем источник синтетически, иначе email-конверсия попадёт в
                    // отчёте в «(без UTM)». Делаем тем же UPDATE, что и email_message_id.
                    $attrUpdate = [];
                    if (empty($orderRow['email_message_id'])) {
                        $attrUpdate['email_message_id'] = $mid;
                    }
                    if (empty($orderRow['utm_source'])) {
                        $attrUpdate['utm_source'] = 'email';
                        $attrUpdate['utm_medium'] = 'trigger';
                    }
                    if (!empty($attrUpdate)) {
                        $dbHelper->update('orders', $attrUpdate, 'id = ?', [$orderId]);
                    }
                    logWebhook('INFO', $paymentId, "Email conversion attributed: order {$orderNumber} → mid {$mid}", '');
                }
            }
        } catch (\Throwable $e) {
            logWebhook('WARNING', $paymentId, "Email attribution failed: " . $e->getMessage(), '');
        }

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
            require_once BASE_PATH . '/classes/CoursePriceAB.php';

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

                        $paidStage = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:UC_8RO3WZ';

                        if (empty($enrollment['bitrix_lead_id'])) {
                            // Сделка ещё не создана — создаём с этапом "Оплата на сайте"
                            $course = $courseObj->getById($enrollment['course_id']);
                            if ($course) {
                                // OPPORTUNITY = фактически уплачено (order_items.price уже
                                // содержит final_amount c учётом таймер-скидки 10%, loyalty,
                                // email-кампании и AB-варианта; см. Order::createForCourseEnrollment).
                                $paidAmount = floatval($item['price']);

                                $dealId = $bitrix->createCourseDeal([
                                    'user_id' => $enrollment['user_id'] ?? null,
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
                                ], $course, $paidStage, $paidAmount);

                                if ($dealId) {
                                    $dbHelper->update('course_enrollments', [
                                        'bitrix_lead_id' => $dealId,
                                        'bitrix_stage' => $paidStage,
                                    ], 'id = ?', [$item['course_enrollment_id']]);
                                    logWebhook('INFO', $paymentId, "Bitrix24 course deal {$dealId} created (stage: {$paidStage}, OPPORTUNITY={$paidAmount}) for enrollment {$item['course_enrollment_id']}", '');
                                }
                            }
                        } else {
                            // Сделка уже создана (cron-ом или ajax-ом) — пытаемся перевести в "Оплаченная сделка".
                            // Сначала проверяем CATEGORY_ID: если сделка перенесена менеджером в чужую воронку
                            // (например, ЦДО для подготовки документов), не трогаем — она уже в работе.
                            $coursePipelineId = defined('BITRIX24_COURSE_PIPELINE_ID') ? (int)BITRIX24_COURSE_PIPELINE_ID : 108;
                            $dealData = $bitrix->getDeal($enrollment['bitrix_lead_id']);
                            $dealCategory = $dealData ? (int)($dealData['CATEGORY_ID'] ?? -1) : -1;

                            if ($dealCategory !== $coursePipelineId) {
                                // Сделка перенесена менеджером в чужую воронку (например, ЦДО).
                                // Перенести её обратно через API нельзя (crm.deal.update игнорирует
                                // CATEGORY_ID), поэтому создаём НОВУЮ сделку в воронке курсов на
                                // этапе «Оплаченная сделка», а в старой сделке оставляем комментарий.
                                $course = $courseObj->getById($enrollment['course_id']);
                                $oldDealId = $enrollment['bitrix_lead_id'];
                                if ($course) {
                                    $paidAmount = floatval($item['price']);
                                    $newDealId = $bitrix->createCourseDeal([
                                        'user_id' => $enrollment['user_id'] ?? null,
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
                                    ], $course, $paidStage, $paidAmount);

                                    if ($newDealId) {
                                        $dbHelper->update('course_enrollments', [
                                            'bitrix_lead_id' => $newDealId,
                                            'bitrix_stage' => $paidStage,
                                        ], 'id = ?', [$item['course_enrollment_id']]);

                                        try {
                                            $bitrix->addDealComment($oldDealId,
                                                "Клиент оплатил этот курс онлайн на сайте (" . number_format($paidAmount, 2, ',', ' ') . " ₽). "
                                                . "Оплата отражена новой сделкой #{$newDealId} в воронке «ФГОС-Практикум (Курсы)» → «Оплаченная сделка». "
                                                . "Эта сделка в воронке #{$dealCategory} — возможный дубль.");
                                        } catch (Exception $e) {
                                            logWebhook('WARNING', $paymentId, "Bitrix24 addDealComment failed for old deal {$oldDealId}: " . $e->getMessage(), '');
                                        }

                                        logWebhook('INFO', $paymentId, "Bitrix24 deal {$oldDealId} is in pipeline {$dealCategory} (not {$coursePipelineId}) — created new course deal {$newDealId} (stage: {$paidStage}, OPPORTUNITY={$paidAmount}) for enrollment {$item['course_enrollment_id']}", '');
                                    } else {
                                        logWebhook('ERROR', $paymentId, "Bitrix24 failed to create course deal for enrollment {$item['course_enrollment_id']} (old deal {$oldDealId} in pipeline {$dealCategory})", '');
                                    }
                                } else {
                                    logWebhook('WARNING', $paymentId, "Bitrix24: course #{$enrollment['course_id']} not found, skipped new deal for enrollment {$item['course_enrollment_id']}", '');
                                }
                            } else {
                                $moved = $bitrix->moveDeal($enrollment['bitrix_lead_id'], $paidStage);
                                if ($moved) {
                                    // Скорректировать OPPORTUNITY на фактически уплаченную сумму
                                    // (учитывает таймер-скидку 10 минут, loyalty, email-кампанию).
                                    $paidAmount = floatval($item['price']);
                                    try {
                                        $bitrix->updateDeal($enrollment['bitrix_lead_id'], [
                                            'OPPORTUNITY' => $paidAmount,
                                            'CURRENCY_ID' => 'RUB',
                                        ]);
                                    } catch (Exception $e) {
                                        logWebhook('WARNING', $paymentId, "Bitrix24 updateDeal OPPORTUNITY failed for deal {$enrollment['bitrix_lead_id']}: " . $e->getMessage(), '');
                                    }
                                    $dbHelper->update('course_enrollments', [
                                        'bitrix_stage' => $paidStage,
                                    ], 'id = ?', [$item['course_enrollment_id']]);
                                    logWebhook('INFO', $paymentId, "Bitrix24 deal {$enrollment['bitrix_lead_id']} moved to {$paidStage}, OPPORTUNITY={$paidAmount} for enrollment {$item['course_enrollment_id']}", '');
                                } else {
                                    logWebhook('ERROR', $paymentId, "Bitrix24 moveDeal FAILED for deal {$enrollment['bitrix_lead_id']} → {$paidStage} (enrollment {$item['course_enrollment_id']})", '');
                                }
                            }
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

        // Письмо-подтверждение оплаты курса (отмену email-цепочек уже сделал fulfillOrderItems).
        try {
            require_once BASE_PATH . '/classes/CourseEmailChain.php';
            $courseEmailChain = new CourseEmailChain($GLOBALS['db']);
            foreach ($orderItems as $item) {
                if (!empty($item['course_enrollment_id'])) {
                    $courseEmailChain->sendPaymentConfirmation($item['course_enrollment_id'], $orderNumber);
                    logWebhook('INFO', $paymentId, "Course payment confirmation sent for enrollment {$item['course_enrollment_id']}", '');
                }
            }
        } catch (Exception $e) {
            logWebhook('WARNING', $paymentId, "Course payment confirmation error: " . $e->getMessage(), '');
        }

        if ($allDocsReady) {
            // Send success email with all attachments
            try {
                try {
                    sendPaymentSuccessEmail($userId, $orderId);
                } catch (Exception $sendErr) {
                    // Временный SMTP-сбой — переносим в очередь, cron повторит
                    // через 10 минут с экспоненциальным backoff'ом.
                    logWebhook('WARNING', $paymentId, "payment_success send failed, queued for retry: " . $sendErr->getMessage(), '');
                    scheduleDelayedEmail('payment_success', (int)$userId, (int)$orderId, 10);
                }

                // Погасить скидку email-кампании (если применялась) — чтобы
                // ей нельзя было воспользоваться повторно.
                try {
                    EmailCampaignDiscount::markUsed($GLOBALS['db'], (int)$userId, (int)$orderId);
                } catch (Exception $e) {
                    logWebhook('WARNING', $paymentId, "Campaign discount mark-used failed: " . $e->getMessage(), '');
                }

                // Пожизненная скидка лояльности: выдать статус и отправить
                // приветственное письмо после первого успешного платежа.
                try {
                    if (LoyaltyDiscount::isFirstSuccessfulOrder($GLOBALS['db'], (int)$userId, (int)$orderId)) {
                        $userObjLocal = new User($GLOBALS['db']);
                        if ($userObjLocal->grantLifetimeDiscount((int)$userId)) {
                            // Откладываем приветственное письмо: payment_success
                            // только что улетел тому же получателю, и Яндекс 360
                            // классифицирует back-to-back пары как outbound-spam.
                            scheduleDelayedEmail('lifetime_discount_granted', (int)$userId, (int)$orderId, 10);
                            logWebhook('SUCCESS', $paymentId, "Lifetime discount granted for user {$userId} (email scheduled +10m)", '');
                        }
                    }
                } catch (Exception $e) {
                    logWebhook('WARNING', $paymentId, "Lifetime discount grant failed: " . $e->getMessage(), '');
                }

                // Mark certificate_email_sent for webinar registrations
                $webRegObj = new WebinarRegistration($GLOBALS['db']);
                $webCertObj = new WebinarCertificate($GLOBALS['db']);
                foreach ($orderItems as $item) {
                    if (!empty($item['webinar_certificate_id'])) {
                        $wcData = $webCertObj->getById($item['webinar_certificate_id']);
                        if ($wcData && !empty($wcData['registration_id'])) {
                            $webRegObj->markCertificateEmailSent($wcData['registration_id']);
                            logWebhook('INFO', $paymentId, "certificate_email_sent marked for registration {$wcData['registration_id']}", '');
                        }
                    }
                }
                logWebhook('SUCCESS', $paymentId, "Payment success email sent for order {$orderNumber}", '');
            } catch (Exception $e) {
                logWebhook('WARNING', $paymentId, "Email failed for order {$orderNumber}: " . $e->getMessage(), '');
            }
        } else {
            $missing = implode(', ', $fulfillResult['missing']);
            logWebhook('ERROR', $paymentId, "EMAIL NOT SENT for order {$orderNumber} - documents not ready: {$missing}", '');

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

        // Снять резерв с cart_items — позиции снова видны в корзине,
        // юзер сможет повторить попытку оплаты.
        releaseCartItemsReservation((int)$orderId);

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

        // Возврат подписки: если этот заказ активировал подписку — отзываем её.
        // Токены уже выданного слота не отзываем (они потрачены/учтены).
        if (!empty($order['subscription_plan_id'])) {
            $stmt = $GLOBALS['db']->prepare(
                "UPDATE user_subscriptions
                    SET status = 'cancelled', cancelled_at = NOW(), auto_renew = 0, expires_at = NOW()
                  WHERE order_id = ?"
            );
            $stmt->execute([$orderId]);
            logWebhook('INFO', $paymentId, "Subscription cancelled due to refund of order {$orderNumber}", '');
        }

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

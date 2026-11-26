<?php
/**
 * Create Payment AJAX Endpoint
 * Yookassa payment integration
 * Supports both competition registrations and publication certificates
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Registration.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/WebinarCertificate.php';
require_once __DIR__ . '/../classes/OlympiadRegistration.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/CartPaymentRetry.php';
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../classes/LoyaltyDiscount.php';
require_once __DIR__ . '/../classes/EmailCampaignDiscount.php';
require_once __DIR__ . '/../classes/ParticipantGroup.php';
require_once __DIR__ . '/../includes/group-pricing.php';
require_once __DIR__ . '/../includes/cart-quote.php';
require_once __DIR__ . '/../includes/order-fulfillment.php';
require_once __DIR__ . '/../vendor/autoload.php';

use YooKassa\Client;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Откройте форму оплаты в корзине.']);
    return;
}
// Validate CSRF token
if (!is_string($_POST['csrf_token'] ?? null) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Недействительный CSRF токен'
    ]);
    exit;
}

try {
    $accountId = verifiedAccountId($db);
    $orderObj = new Order($db);
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    if (isset($_POST['resume_order_id'])) {
        $resumeId = filter_var($_POST['resume_order_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$resumeId) throw new DomainException('Некорректный заказ.');
        echo json_encode(CartPaymentRetry::resume($db, $resumeId, $accountId, $client), JSON_UNESCAPED_UNICODE);
        return;
    }

    $db->beginTransaction();
    if ($accountId !== null) {
        $lock = $db->prepare('SELECT id FROM cart_items WHERE user_id = ? ORDER BY id FOR UPDATE');
        $lock->execute([$accountId]);
        $lock->fetchAll(PDO::FETCH_COLUMN);
    }
    refreshCheckoutCart($accountId);
    $documents = loadCartDocuments($db, $accountId);
    $openOrders = CartPaymentRetry::openOrders($db, $accountId);
    $available = CartPaymentRetry::unreservedItems($documents, $openOrders);
    if ($documents && !$available && count($openOrders) === 1 && is_string($_POST['cart_revision'] ?? null)) {
        $shown = quoteCart($db, $documents, $accountId);
        if (hash_equals($shown['revision'], $_POST['cart_revision']) && CartPaymentRetry::matchesShownQuote($openOrders[0], $shown)) {
            $db->commit();
            echo json_encode(CartPaymentRetry::resume($db, (int)$openOrders[0]['id'], $accountId, $client), JSON_UNESCAPED_UNICODE);
            return;
        }
    }
    $quote = quoteCart($db, $available, $accountId);
    if (!is_string($_POST['cart_revision'] ?? null) || !hash_equals($quote['revision'], $_POST['cart_revision'])) {
        $db->rollBack();
        echo json_encode(['success' => false, 'refresh_cart' => true,
            'message' => 'Корзина изменилась. Проверьте обновлённый состав и сумму перед оплатой.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $allItems = $quote['items'];
    if (!$allItems) throw new DomainException('Корзина пуста. Обновите страницу.');
    $grandTotal = $quote['total'];
    $subscriber = $quote['subscriber'];
    $userId = (int)$allItems[0]['raw_data']['user_id'];
    $user = (new User($db))->getById($userId);
    if (empty($user['email'])) throw new DomainException('Не удалось определить получателя чека.');
    $userEmail = $user['email'];
    $retry = CartPaymentRetry::resolve($db, $userId, (int)$accountId, $allItems, $grandTotal,
        static function (): never {
            throw new DomainException('В корзине появилась начатая оплата. Обновите страницу.');
        });
    if (isset($retry['redirect_url'])) {
        $db->commit();
        echo json_encode($retry, JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!$subscriber && !$retry && PricingMode::isSubscriptionOnly()) {
        $db->rollBack();
        echo json_encode(['success' => false, 'requires_subscription' => true,
            'redirect_url' => '/podpiska/?from=cart', 'message' => 'Получить документы можно по подписке.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $loyaltyDiscount = $quote['loyalty'] + $quote['campaign_amount'];
    $cartData = ['items' => [], 'subtotal' => $subscriber ? 0 : $quote['subtotal'], 'discount' => $quote['discount'],
        'group_discount' => $quote['group_discount'], 'loyalty_discount' => $loyaltyDiscount, 'total' => $grandTotal,
        'promotion_applied' => $quote['discount'] > 0];
    $grouped = ['registration' => [], 'certificate' => [], 'webinar_certificate' => [], 'olympiad_registration' => []];
    foreach ($allItems as $item) {
        $row = $item['raw_data'];
        $row['is_free'] = $subscriber || $item['is_free'];
        $row['price'] = $subscriber ? 0 : $item['price'];
        $grouped[$item['type']][] = $row;
        if ($item['type'] === 'registration') {
            $cartData['items'][] = ['registration_id' => $item['id'], 'price' => $row['price'], 'is_free' => $row['is_free']];
        }
    }
    $certificatesData = $grouped['certificate'];
    $registrations = array_column($grouped['registration'], 'id');
    $certificates = array_column($certificatesData, 'id');
    $webinarCertificates = array_column($grouped['webinar_certificate'], 'id');
    $olympiadRegistrations = array_column($grouped['olympiad_registration'], 'id');
    $orderId = $retry['covered_order_id'] ?? $retry['retry_order_id'] ?? $orderObj->createFromCart($userId, $cartData,
        $certificatesData, $grandTotal, $grouped['webinar_certificate'], $grouped['olympiad_registration']);
    if (!$orderId) throw new DomainException('Не удалось создать заказ.');
    if (!$retry) {
        PricingMode::stampOrder($db, (int)$orderId);
        if (!reserveCartItemsForOrder($userId, CartPaymentRetry::reservationKeys($allItems), (int)$orderId)) {
            throw new DomainException('В корзине есть незавершённая оплата. Обновите страницу.');
        }
        if ($subscriber) {
            $db->prepare('UPDATE order_items SET covered_by_subscription = 1 WHERE order_id = ?')->execute([$orderId]);
        }
    }
    $order = $orderObj->getById($orderId);
    $orderNumber = $order['order_number'];
    // Право гостя записываем до фиксации заказа. При откате автоинкрементный ID не используется повторно.
    if ($accountId === null) grantGuestOrder((int)$orderId, $userId);
    if ($subscriber || isset($retry['covered_order_id']) || APP_ENV === 'local') {
        $db->commit();
        fulfillOrderItems($db, (int)$orderId, ($subscriber || isset($retry['covered_order_id'])) ? 'subscription' : 'local');
        echo json_encode(CartPaymentRetry::statusPage($order), JSON_UNESCAPED_UNICODE);
        return;
    }

    // Сохраняем UTM-атрибуцию на заказе (first-click attribution)
    $utmSource = mb_substr(trim($_POST['utm_source'] ?? ''), 0, 255) ?: null;
    $utmMedium = mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 255) ?: null;
    $utmCampaign = mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 255) ?: null;
    $utmContent = mb_substr(trim($_POST['utm_content'] ?? ''), 0, 255) ?: null;
    $utmTerm = mb_substr(trim($_POST['utm_term'] ?? ''), 0, 255) ?: null;
    $visitId = intval($_POST['visit_id'] ?? 0) ?: null;

    // Fallback: если в сессии нет UTM (пользователь вернулся по триггерной рассылке),
    // берём UTM из регистрации/записи — атрибуция первого клика
    if (!$retry && !$utmSource) {
        $dbFallback = new Database($db);
        $fallbackUtm = null;

        // 1. Регистрации на конкурсы
        if (!$fallbackUtm && !empty($registrations)) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM registrations WHERE id = ? AND utm_source IS NOT NULL",
                [$registrations[0]]
            );
        }

        // 2. Олимпиадные регистрации
        if (!$fallbackUtm && !empty($olympiadRegistrations)) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM olympiad_registrations WHERE id = ? AND utm_source IS NOT NULL",
                [$olympiadRegistrations[0]]
            );
        }

        // 2b. Сертификаты публикаций (utm фиксируется при создании сертификата,
        // см. PublicationCertificate::create). $certificatesData содержит pc.*,
        // поэтому utm-колонки уже загружены — без отдельного запроса.
        if (!$fallbackUtm && !empty($certificatesData) && !empty($certificatesData[0]['utm_source'])) {
            $fallbackUtm = [
                'utm_source'   => $certificatesData[0]['utm_source'],
                'utm_medium'   => $certificatesData[0]['utm_medium'] ?? null,
                'utm_campaign' => $certificatesData[0]['utm_campaign'] ?? null,
                'utm_content'  => $certificatesData[0]['utm_content'] ?? null,
                'utm_term'     => $certificatesData[0]['utm_term'] ?? null,
            ];
        }

        // 3. Записи на курсы (course_enrollments уже сохраняют UTM)
        if (!$fallbackUtm && $userId) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM course_enrollments WHERE user_id = ? AND utm_source IS NOT NULL
                 ORDER BY created_at DESC LIMIT 1",
                [$userId]
            );
        }

        // 4. Первый визит пользователя с UTM (атрибуция первого клика)
        if (!$fallbackUtm && $userId) {
            $fallbackUtm = $dbFallback->queryOne(
                "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
                 FROM visits WHERE user_id = ? AND utm_source IS NOT NULL AND utm_source != ''
                 ORDER BY started_at ASC LIMIT 1",
                [$userId]
            );
        }

        // 5. Cookie _fgos_utm_* (90 дней) — первый клик переживает закрытие браузера
        // и переход из почтового клиента. Заполняется visit-tracker.js и magic-auth.php.
        if (!$fallbackUtm && !empty($_COOKIE['_fgos_utm_source'])) {
            $fallbackUtm = [
                'utm_source'   => mb_substr(trim((string)$_COOKIE['_fgos_utm_source']), 0, 255),
                'utm_medium'   => mb_substr(trim((string)($_COOKIE['_fgos_utm_medium'] ?? '')), 0, 255) ?: null,
                'utm_campaign' => mb_substr(trim((string)($_COOKIE['_fgos_utm_campaign'] ?? '')), 0, 255) ?: null,
                'utm_content'  => mb_substr(trim((string)($_COOKIE['_fgos_utm_content'] ?? '')), 0, 255) ?: null,
                'utm_term'     => mb_substr(trim((string)($_COOKIE['_fgos_utm_term'] ?? '')), 0, 255) ?: null,
            ];
        }

        if ($fallbackUtm && !empty($fallbackUtm['utm_source'])) {
            $utmSource   = $fallbackUtm['utm_source'];
            $utmMedium   = $fallbackUtm['utm_medium'] ?? null;
            $utmCampaign = $fallbackUtm['utm_campaign'] ?? null;
            $utmContent  = $fallbackUtm['utm_content'] ?? null;
            $utmTerm     = $fallbackUtm['utm_term'] ?? null;
        }

        // 6. Last resort: пользователь пришёл по триггерному письму (есть email_mid),
        // но UTM так и не нашлись ни в одной таблице. Помечаем синтетически как email/trigger,
        // чтобы заказ не попал в «без UTM».
        if (!$utmSource) {
            $emailMidForAttr = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
            if ($emailMidForAttr) {
                $utmSource = 'email';
                $utmMedium = 'trigger';
            }
        }
    }

    if (!$retry && ($utmSource || $utmMedium || $utmCampaign || $utmContent || $utmTerm || $visitId)) {
        $dbObj = new Database($db);
        $dbObj->update('orders', [
            'utm_source' => $utmSource,
            'utm_medium' => $utmMedium,
            'utm_campaign' => $utmCampaign,
            'utm_content' => $utmContent,
            'utm_term' => $utmTerm,
            'visit_id' => $visitId,
        ], 'id = ?', [$orderId]);
    }

    // Email-атрибуция: если пользователь пришёл по клику из письма —
    // сохраняем message_id, чтобы webhook смог приписать оплату к конкретному письму.
    $emailMid = $_SESSION['email_mid'] ?? ($_COOKIE['email_mid'] ?? null);
    if (!$retry && $emailMid && preg_match('~^[a-f0-9]{32}$~', $emailMid)) {
        (new Database($db))->update('orders', ['email_message_id' => $emailMid], 'id = ?', [$orderId]);
    }


    // Prepare receipt items for 54-ФЗ compliance (with unified 2+1 promotion)
    $receiptItems = [];

    // Распределяем loyalty-скидку пропорционально по оплачиваемым позициям,
    // чтобы сумма строк чека совпала с итоговой суммой платежа.
    $payablePrices = [];
    $payableIndex = [];
    foreach ($allItems as $i => $item) {
        if (!$item['is_free'] && $item['price'] > 0) {
            $payablePrices[$i] = (float)$item['price'];
            $payableIndex[$i] = count($payablePrices) - 1;
        }
    }
    $adjustedPrices = $loyaltyDiscount > 0
        ? LoyaltyDiscount::distributePricesWithDiscount(array_values($payablePrices), $loyaltyDiscount)
        : array_values($payablePrices);

    // Add ALL items (registrations and certificates) with promotion applied
    foreach ($allItems as $i => $item) {
        $itemPrice = $item['is_free'] ? 0 : $item['price'];
        if ($itemPrice > 0 && isset($payableIndex[$i])) {
            $itemPrice = $adjustedPrices[$payableIndex[$i]];
        }
        if ($itemPrice > 0) {
            if ($item['type'] === 'certificate') {
                $description = 'Свидетельство о публикации: ' . mb_substr($item['name'], 0, 100);
            } elseif ($item['type'] === 'webinar_certificate') {
                $description = 'Сертификат вебинара: ' . mb_substr($item['name'], 0, 100);
            } elseif ($item['type'] === 'olympiad_registration') {
                $description = 'Диплом олимпиады: ' . mb_substr($item['name'], 0, 100);
            } else {
                $description = mb_substr($item['name'], 0, 128);
            }

            $receiptItems[] = [
                'description' => $description,
                'quantity' => 1,
                'amount' => [
                    'value' => number_format($itemPrice, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                'vat_code' => 1, // НДС не облагается
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ];
        }
    }

    $payload = [
        'amount' => [
            'value' => number_format($grandTotal, 2, '.', ''),
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
            'payment_type' => 'cart',
            'order_id' => (string)$orderId,
            'order_number' => $orderNumber,
            'user_id' => (string)$userId,
            'user_email' => $userEmail,
            'certificate_ids' => !empty($certificates) ? implode(',', $certificates) : null,
            'webinar_certificate_ids' => !empty($webinarCertificates) ? implode(',', $webinarCertificates) : null,
            'olympiad_registration_ids' => !empty($olympiadRegistrations) ? implode(',', $olympiadRegistrations) : null,
        ],
    ];
    if ($retry) $payload = CartPaymentRetry::savedPayload($db, (int)$orderId) ?? $payload;
    \YooKassa\Request\Payments\CreatePaymentRequest::builder()->build($payload);
    CartPaymentRetry::rememberPayload($db, (int)$orderId, $payload, $retry !== null);
    if (!session_write_close()) throw new DomainException('Не удалось сохранить сессию оплаты.');
    $db->commit();
    $response = CartPaymentRetry::dispatch($db, $client, $order, $payload, $retry !== null);
    logPayment('CREATE', $orderNumber, $response['payment_id'] ?? null, 'Оформление заказа', $grandTotal);
    echo json_encode($response, JSON_UNESCAPED_UNICODE);

} catch (DomainException $e) {
    if ($db->inTransaction()) $db->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if ($db->inTransaction()) $db->rollBack();
    logPayment('ERROR', $orderNumber ?? 'unknown', null, 'Ошибка оформления: ' . get_class($e) . ' (' . $e->getCode() . ')', 0);
    echo json_encode([
        'success' => false,
        'message' => 'Не удалось подтвердить статус оплаты. Через несколько минут откройте корзину в своём аккаунте и нажмите «Перейти к оплате».',
    ], JSON_UNESCAPED_UNICODE);
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

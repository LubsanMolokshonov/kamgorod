<?php
/**
 * AJAX: создание Yookassa-платежа на подписку (Базовый / Про).
 *
 * POST:
 *   - csrf
 *   - plan_slug (basic|pro)
 *   - period (monthly|yearly)
 *
 * Ответ: { success:true, confirmation_url } | { success:false, error, code }
 *
 * Webhook (api/webhook/yookassa.php) распознаёт metadata.payment_type='subscription'
 * и активирует подписку через SubscriptionService::activate().
 * Заказ создаётся в orders с subscription_plan_id (без order_items).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../vendor/autoload.php';

use YooKassa\Client;
use YooKassa\Common\Exceptions\ForbiddenException;

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    respond(['success' => false, 'error' => 'Войдите, чтобы оформить подписку', 'code' => 'unauthorized'], 401);
}

if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    respond(['success' => false, 'error' => 'Сессия истекла. Обновите страницу.', 'code' => 'csrf'], 403);
}

$planSlug = strtolower(trim((string)($_POST['plan_slug'] ?? '')));
$period   = strtolower(trim((string)($_POST['period'] ?? 'monthly')));
// Автопродление включено по умолчанию (opt-out галочкой). save_payment_method=true сохранит
// карту → последующие списания пойдут рекуррентом (cron/renew-subscriptions.php).
$autoRenew = !isset($_POST['auto_renew']) || (string)$_POST['auto_renew'] === '1';
// Пока магазин YooKassa не подключён к рекуррентам — автопродление выключено глобально
// (см. SUBSCRIPTION_AUTORENEW_ENABLED). Не просим сохранять карту, подписка разовая.
if (!SUBSCRIPTION_AUTORENEW_ENABLED) {
    $autoRenew = false;
}
if (!in_array($planSlug, ['basic', 'pro'], true)) {
    respond(['success' => false, 'error' => 'Неизвестный тариф', 'code' => 'invalid_plan'], 400);
}
if (!in_array($period, ['monthly', 'yearly'], true)) {
    respond(['success' => false, 'error' => 'Неизвестный период', 'code' => 'invalid_period'], 400);
}

$dbHelper = new Database($db);
$plan = $dbHelper->queryOne(
    "SELECT * FROM subscription_plans WHERE slug = ? AND is_active = 1",
    [$planSlug]
);
if (!$plan) {
    respond(['success' => false, 'error' => 'Тариф недоступен', 'code' => 'plan_not_found'], 404);
}

$priceRub = $period === 'yearly' ? (float)$plan['price_yearly'] : (float)$plan['price_monthly'];
if ($priceRub <= 0) {
    respond(['success' => false, 'error' => 'Некорректная цена тарифа', 'code' => 'invalid_price'], 400);
}

$userRow = $dbHelper->queryOne("SELECT email FROM users WHERE id = ?", [$userId]);
$userEmail = $userRow['email'] ?? '';

$periodLabel = $period === 'yearly' ? 'год' : 'месяц';
$description = "Подписка «{$plan['name']}» на fgos.pro ({$periodLabel})";

// Создаём заказ-подписку (без order_items). final_amount = цена тарифа, статус pending.
$orderNumber = Order::generateOrderNumber();
$orderId = $dbHelper->insert('orders', [
    'user_id'              => (int)$userId,
    'order_number'         => $orderNumber,
    'total_amount'         => $priceRub,
    'discount_amount'      => 0,
    'final_amount'         => $priceRub,
    'payment_status'       => 'pending',
    'subscription_plan_id' => (int)$plan['id'],
    'subscription_period'  => $period,
]);
if (!$orderId) {
    respond(['success' => false, 'error' => 'Не удалось создать заказ', 'code' => 'order_error'], 500);
}

// A/B-атрибуция: помечаем заказ-подписку вариантом модели оплаты.
PricingMode::stampOrder($db, (int)$orderId);

$idempotencyKey = 'sub_' . $userId . '_' . $plan['id'] . '_' . $period . '_' . substr(uniqid('', true), -10);

// Сборка payload платежа. $saveCard=true просит YooKassa сохранить карту (рекуррент);
// metadata.auto_renew синхронизируется с этим флагом, чтобы вебхук активировал подписку
// ровно с тем автопродлением, которое мы реально способны обеспечить.
$buildPayment = static function (bool $saveCard) use ($priceRub, $description, $userEmail, $userId, $orderId, $plan, $planSlug, $period): array {
    return [
        'amount' => [
            'value' => number_format($priceRub, 2, '.', ''),
            'currency' => 'RUB',
        ],
        'confirmation' => [
            'type' => 'redirect',
            'return_url' => SITE_URL . '/pages/cabinet.php?subscription=success',
        ],
        'capture' => true,
        'description' => $description,
        'receipt' => $userEmail ? [
            'customer' => ['email' => $userEmail],
            'items' => [[
                'description' => $description,
                'quantity' => 1,
                'amount' => [
                    'value' => number_format($priceRub, 2, '.', ''),
                    'currency' => 'RUB',
                ],
                'vat_code' => 1,
                'payment_mode' => 'full_payment',
                'payment_subject' => 'service',
            ]],
        ] : null,
        'metadata' => [
            'payment_type' => 'subscription',
            'user_id' => (int)$userId,
            'order_id' => (int)$orderId,
            'plan_id' => (int)$plan['id'],
            'plan_slug' => $planSlug,
            'period' => $period,
            'auto_renew' => $saveCard ? '1' : '0',
        ],
        // Автопродление: сохраняем карту (вебхук достанет payment_method.id → активация
        // с auto_renew=1). Если карта не сохраняется — подписка без автопродления.
        'save_payment_method' => $saveCard,
    ];
};

try {
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    try {
        $payment = $client->createPayment($buildPayment($autoRenew), $idempotencyKey);
    } catch (ForbiddenException $fe) {
        // Магазин в YooKassa не подключён к рекуррентным платежам (code: forbidden,
        // "This store can't make recurring payments"). Не ломаем оплату: пересоздаём
        // платёж как разовый, без сохранения карты. Подписка активируется, но без
        // автопродления — пока YooKassa не включит рекуррент магазину (тогда галка
        // заработает сама, без правок кода).
        if (!$autoRenew) {
            throw $fe; // карту и не просили — это другая ошибка, пробрасываем
        }
        error_log('create-subscription-payment: recurring disabled for shop, fallback to one-time. ' . $fe->getMessage());
        // Новый ключ идемпотентности: тело запроса изменилось.
        $payment = $client->createPayment($buildPayment(false), $idempotencyKey . '_nr');
    }

    (new Order($db))->updateYookassaDetails(
        $orderId,
        $payment->getId(),
        $payment->getConfirmation()->getConfirmationUrl()
    );

    respond([
        'success' => true,
        'confirmation_url' => $payment->getConfirmation()->getConfirmationUrl(),
        'payment_id' => $payment->getId(),
    ]);
} catch (Throwable $e) {
    error_log('create-subscription-payment Yookassa error: ' . $e->getMessage());
    // Заказ остаётся pending — повторная попытка создаст новый.
    respond([
        'success' => false,
        'error' => 'Не удалось создать платёж. Попробуйте ещё раз.',
        'code' => 'yookassa_error',
    ], 502);
}

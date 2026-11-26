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

$requestKey = $_POST['request_key'] ?? null;
if ($requestKey !== null && (!is_string($requestKey) || !preg_match('/^[a-f0-9]{32}$/D', $requestKey))) {
    respond(['success' => false, 'error' => 'Обновите форму оплаты.', 'code' => 'request_key'], 400);
}

try {
    require_once __DIR__ . '/../classes/SubscriptionPayments.php';
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);
    $result = (new SubscriptionPayments($db, $client))->manual((int)$userId, $plan, $period, $autoRenew, $requestKey);
    respond(['success' => true, 'confirmation_url' => $result['confirmation_url'], 'payment_id' => $result['payment_id'] ?? null]);
} catch (DomainException $e) {
    respond(['success' => false, 'error' => $e->getMessage(), 'code' => 'payment_state'], 409);
} catch (Throwable $e) {
    error_log('Создание платежа подписки: ' . get_class($e));
    respond(['success' => false, 'error' => 'Проверяем платёж. Попробуйте ещё раз.', 'code' => 'yookassa_error'], 502);
}

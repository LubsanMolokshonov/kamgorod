<?php
/**
 * AJAX: создание Yookassa-платежа на покупку пакета токенов.
 *
 * POST:
 *   - csrf
 *   - package_id (int)
 *
 * Ответ:
 *   { success: true, confirmation_url: '...' }
 *   { success: false, error, code }
 *
 * Webhook (api/webhook/yookassa.php) распознаёт metadata.payment_type='tokens'
 * и зачисляет токены через UserTokens::credit() с reason='purchase'.
 *
 * Order не создаётся — для токенов используется только Yookassa payment с metadata.
 * Идемпотентность гарантируется через token_transactions.payment_id UNIQUE по reason='purchase'.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/TokenPackage.php';
require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
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
    respond(['success' => false, 'error' => 'Войдите, чтобы купить токены', 'code' => 'unauthorized'], 401);
}

if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    respond(['success' => false, 'error' => 'Сессия истекла', 'code' => 'csrf'], 403);
}

$packageId = (int)($_POST['package_id'] ?? 0);
if ($packageId <= 0) {
    respond(['success' => false, 'error' => 'Не выбран пакет', 'code' => 'invalid_package'], 400);
}

$packageObj = new TokenPackage($db);
$package = $packageObj->getById($packageId);
if (!$package || !$package['is_active']) {
    respond(['success' => false, 'error' => 'Пакет не найден или недоступен', 'code' => 'invalid_package'], 404);
}

// Получим email пользователя для чека (54-ФЗ)
$userRow = (new Database($db))->queryOne("SELECT email FROM users WHERE id = ?", [$userId]);
$userEmail = $userRow['email'] ?? '';

$totalTokens = $packageObj->totalTokens($package);
$originalPrice = (float)$package['price_rub'];
$priceRub = $originalPrice;

// Скидка из письма (HMAC-токен). Влияет только на ЦЕНУ, число токенов не меняется.
$discountPercent = 0;
$discountToken = trim((string)($_POST['discount'] ?? ''));
if ($discountToken !== '') {
    $discountData = MaterialTokenEmailChain::validateDiscountToken($discountToken);
    if ($discountData && (int)$discountData['user_id'] === (int)$userId) {
        $discountPercent = (int)$discountData['percent'];
        $priceRub = round($originalPrice * (100 - $discountPercent) / 100, 2);
    }
}

// Куда вернуть после оплаты (брошенная корзина — назад к материалу). Только относительный путь.
$returnPath = '/material-balance/?paid=1';
$rt = (string)($_POST['return_to'] ?? '');
if ($rt !== '' && $rt[0] === '/' && !str_starts_with($rt, '//') && strlen($rt) <= 300) {
    $returnPath = $rt;
}

$description = "Покупка пакета «{$package['name']}» — {$totalTokens} токенов на fgos.pro"
    . ($discountPercent > 0 ? " (скидка {$discountPercent}%)" : '');
$idempotencyKey = 'tokens_' . $userId . '_' . $packageId . '_' . substr(uniqid('', true), -10);

// UTM-атрибуция канала для РНП. Order на токены не создаётся, поэтому источник
// фиксируем здесь и прокидываем в metadata — webhook сохранит его в token_transactions.
// Приоритет: cookie _fgos_utm_* (visit-tracker.js) → первый визит пользователя с utm.
$utm = ['utm_source' => null, 'utm_medium' => null, 'utm_campaign' => null, 'utm_content' => null, 'utm_term' => null];
foreach ($utm as $utmKey => $_) {
    $cookieVal = $_COOKIE['_fgos_' . $utmKey] ?? '';
    if ($cookieVal !== '') {
        $utm[$utmKey] = mb_substr(trim((string)$cookieVal), 0, 255);
    }
}
if (empty($utm['utm_source'])) {
    $firstVisit = (new Database($db))->queryOne(
        "SELECT utm_source, utm_medium, utm_campaign, utm_content, utm_term
         FROM visits WHERE user_id = ? AND utm_source IS NOT NULL AND utm_source != ''
         ORDER BY started_at ASC LIMIT 1",
        [$userId]
    );
    if ($firstVisit) {
        foreach ($utm as $utmKey => $_) {
            if (!empty($firstVisit[$utmKey])) {
                $utm[$utmKey] = mb_substr(trim((string)$firstVisit[$utmKey]), 0, 255);
            }
        }
    }
}
$utmMetadata = array_filter($utm, fn($v) => $v !== null && $v !== '');

try {
    $client = new Client();
    $client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);

    $payment = $client->createPayment(
        [
            'amount' => [
                'value' => number_format($priceRub, 2, '.', ''),
                'currency' => 'RUB',
            ],
            'confirmation' => [
                'type' => 'redirect',
                'return_url' => SITE_URL . $returnPath,
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
            'metadata' => array_merge([
                'payment_type' => 'tokens',
                'user_id' => (int)$userId,
                'package_id' => $packageId,
                'tokens_total' => $totalTokens,
                'discount_percent' => $discountPercent,
                'original_price' => number_format($originalPrice, 2, '.', ''),
            ], $utmMetadata),
        ],
        $idempotencyKey
    );

    respond([
        'success' => true,
        'confirmation_url' => $payment->getConfirmation()->getConfirmationUrl(),
        'payment_id' => $payment->getId(),
    ]);
} catch (Throwable $e) {
    error_log('buy-tokens Yookassa error: ' . $e->getMessage());
    respond([
        'success' => false,
        'error' => 'Не удалось создать платёж. Попробуйте ещё раз.',
        'code' => 'yookassa_error',
    ], 502);
}

<?php
/**
 * Разовый фулфилмент подписок, оплаченных, но НЕ активированных из-за бага вебхука.
 *
 * Баг (введён коммитом подписки 19.06, исправлен 22.06): ветки tokens/subscription в
 * api/webhook/yookassa.php ссылались на несуществующий класс
 * \YooKassa\Model\Payment\PaymentStatus (правильный — \YooKassa\Model\PaymentStatus),
 * из-за чего вебхук падал FATAL на каждом subscription-платеже и подписка не активировалась.
 *
 * Затронуты оплаченные (succeeded в Yookassa) заказы подписок:
 *   4784 — user 7991 (31c82023…) succeeded, paid
 *   4785 — user 8000 Новиков Денис (31c8be59…) succeeded, paid  ← алерты 153/154/155
 * (4786 — canceled, 4789 — pending: их НЕ трогаем)
 *
 * Действия по каждому: updatePaymentStatus('succeeded') → SubscriptionService::activate (идемпотентна по order_id).
 * Письмо «подписка активна» best-effort (как в вебхуке).
 *
 * Запуск:
 *   docker exec pedagogy_web php /var/www/html/scripts/fulfill_subscriptions_20260622.php          (DRY-RUN)
 *   docker exec pedagogy_web php /var/www/html/scripts/fulfill_subscriptions_20260622.php --send    (живо)
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        fwrite(STDERR, "\n[SHUTDOWN FATAL] {$e['message']} @ {$e['file']}:{$e['line']}\n");
    }
});

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Order.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../includes/email-helper.php'; // sendSubscriptionActivatedEmail

$send = in_array('--send', array_slice($argv, 1), true);
$mode = $send ? 'LIVE' : 'DRY-RUN';

$ORDER_IDS = [4779, 4784, 4785]; // все succeeded-в-Yookassa, но pending в БД из-за бага вебхука

$pdo       = $GLOBALS['db'];
$dbw       = new Database($pdo);
$orderObj  = new Order($pdo);
$subSvc    = new SubscriptionService($pdo);

echo "=== Фулфилмент подписок [{$mode}] ===\n";

foreach ($ORDER_IDS as $oid) {
    $order = $orderObj->getById($oid);
    if (!$order) { echo "[SKIP] заказ {$oid} не найден\n"; continue; }

    $uid    = (int)$order['user_id'];
    $planId = (int)$order['subscription_plan_id'];
    $period = (string)($order['subscription_period'] ?: 'monthly');
    echo "\n— Заказ {$oid} ({$order['order_number']}): user={$uid} plan={$planId} period={$period} статус={$order['payment_status']}\n";

    // Уже активировано?
    $existing = $dbw->queryOne("SELECT id FROM user_subscriptions WHERE order_id = ? LIMIT 1", [$oid]);
    if ($existing) { echo "  [OK] подписка уже активна (sub #{$existing['id']}) — пропуск\n"; continue; }

    if (!$send) {
        echo "  [DRY] был бы: updatePaymentStatus('succeeded') + activate(user={$uid}, plan={$planId}, {$period}, order={$oid})\n";
        continue;
    }

    if ($order['payment_status'] !== 'succeeded') {
        $orderObj->updatePaymentStatus($oid, 'succeeded', date('Y-m-d H:i:s'));
        echo "  [DONE] заказ помечен succeeded\n";
    }

    $subId = $subSvc->activate($uid, $planId, $period, $oid, null);
    echo "  [DONE] подписка активирована: sub #{$subId}\n";

    // Письмо «подписка активна» (best-effort, как в вебхуке)
    try {
        if (function_exists('sendSubscriptionActivatedEmail')) {
            sendSubscriptionActivatedEmail($uid, $subId);
            echo "  [DONE] письмо «подписка активна» отправлено\n";
        }
    } catch (Throwable $e) {
        echo "  [WARN] письмо не ушло: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Готово [{$mode}] ===\n";

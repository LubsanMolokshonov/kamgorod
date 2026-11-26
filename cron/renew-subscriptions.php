#!/usr/bin/env php
<?php
/**
 * Cron: рекуррентные автосписания подписок (Этап 2).
 *
 * Берёт активные подписки с auto_renew=1 и сохранённой картой, у которых до конца периода
 * осталось <= SUBSCRIPTION_RENEW_LEAD_DAYS дней, и списывает рекуррентным платежом Yookassa.
 * Активация (продление expires_at) идемпотентна по order_id. Dunning: до
 * SUBSCRIPTION_RENEW_MAX_ATTEMPTS попыток с бэк-оффом 12 ч, затем письмо «не удалось списать».
 * Подробности — classes/SubscriptionRenewal.php.
 *
 * Рекомендуемое расписание: раз в час.
 * Crontab: 27 * * * * docker exec pedagogy_web php /var/www/html/cron/renew-subscriptions.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/SubscriptionRenewal.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('renew-subscriptions');

$lockFile = '/tmp/renew_subscriptions_cron.lock';

if (!SUBSCRIPTION_AUTORENEW_ENABLED) {
    exit(0);
}
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

try {
    echo date('Y-m-d H:i:s') . " - Starting subscription renewals...\n";

    $renewal = new SubscriptionRenewal($db, function (string $level, string $msg): void {
        echo date('Y-m-d H:i:s') . " - {$level} | {$msg}\n";
    });

    $stats = $renewal->run();

    echo date('Y-m-d H:i:s') . " - Done. "
        . "checked={$stats['checked']}, charged={$stats['charged']}, "
        . "failed={$stats['failed']}, errors={$stats['errors']}\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Renew Subscriptions Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_renew-subscriptions',
        '[Cron] Exception: renew-subscriptions',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}

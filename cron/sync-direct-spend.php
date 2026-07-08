#!/usr/bin/env php
<?php
/**
 * Cron: синхронизация расходов Яндекс.Директа из ai.h1pro.ru.
 *
 * Тянет /spend (по умолчанию окно последних 7 дней — Директ пересчитывает статистику
 * задним числом), перезаписывает direct_ad_spend по окну дат и пересчитывает агрегаты:
 * rnp_ad_costs.direct_* (РНП) и direction_weekly_costs.direct_cost (Экономика направлений).
 * Подробности — classes/DirectSpendSync.php.
 *
 * Ручной бэкфилл (до 366 дней):
 *   php cron/sync-direct-spend.php --from=2026-06-01 --to=2026-07-07
 *
 * Рекомендуемое расписание: раз в день после 06:00 МСК.
 * Crontab: 40 6 * * * docker exec pedagogy_web php /var/www/html/cron/sync-direct-spend.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/DirectSpendSync.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('sync-direct-spend');

if (H1PRO_EXPORT_API_KEY === '' || H1PRO_PROJECT_ID === '') {
    echo date('Y-m-d H:i:s') . " - H1PRO_EXPORT_API_KEY / H1PRO_PROJECT_ID не заданы в .env. Exiting.\n";
    exit(0);
}

$args = getopt('', ['from::', 'to::']);
$from = $args['from'] ?? null;
$to   = $args['to'] ?? null;
foreach (['--from' => $from, '--to' => $to] as $name => $val) {
    if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) {
        echo date('Y-m-d H:i:s') . " - Некорректный {$name}={$val}, ожидается YYYY-MM-DD. Exiting.\n";
        exit(1);
    }
}

$lockFile = '/tmp/sync_direct_spend_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_sync_direct_spend',
            '[Cron] Удалён зависший lock: sync-direct-spend',
            ['lock_file' => $lockFile, 'age_sec' => $lockAge],
            'warning'
        );
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    $window = ($from || $to) ? "window {$from}..{$to}" : 'default window (last 7 days)';
    echo date('Y-m-d H:i:s') . " - Starting Direct spend sync, {$window}...\n";

    $sync = new DirectSpendSync($db, function (string $level, string $msg): void {
        echo date('Y-m-d H:i:s') . " - {$level} | {$msg}\n";
    });

    $stats = $sync->sync($from, $to);

    echo date('Y-m-d H:i:s') . " - Done. "
        . "period={$stats['date_from']}..{$stats['date_to']}, "
        . "rows={$stats['rows']}, campaigns={$stats['campaigns']}, unmapped={$stats['unmapped']}, "
        . "rnp_days={$stats['rnp_days']}, direction_weeks={$stats['direction_weeks']}\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Direct Spend Sync Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_sync-direct-spend',
        '[Cron] Exception: sync-direct-spend',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

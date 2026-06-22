#!/usr/bin/env php
<?php
/**
 * Cron: сверка зависших оплат с Yookassa (страховка на случай отказа вебхука).
 *
 * Берёт заказы payment_status='pending' с yookassa_payment_id (возраст 10 мин … 7 дней),
 * спрашивает у Yookassa реальный статус и идемпотентно довыдаёт: succeeded → активация
 * подписки / выдача документов; canceled → пометить failed. Каждое восстановление —
 * алерт в Telegram. Подробности — classes/PaymentReconciliation.php.
 *
 * Рекомендуемое расписание: каждые 15 минут (cron-выражение «слэш-15 * * * *»).
 * Crontab: docker exec pedagogy_web php /var/www/html/cron/reconcile-payments.php
 *
 * Флаг --dry — только показать, что нашлось бы (без починки) НЕ реализован: сверка сама
 * по себе безопасна и идемпотентна, dry не требуется.
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/PaymentReconciliation.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('reconcile-payments');

$lockFile = '/tmp/reconcile_payments_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_reconcile_payments',
            '[Cron] Удалён зависший lock: reconcile-payments',
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
    echo date('Y-m-d H:i:s') . " - Starting payment reconciliation...\n";

    $recon = new PaymentReconciliation($db, function (string $level, string $msg): void {
        echo date('Y-m-d H:i:s') . " - {$level} | {$msg}\n";
    });

    $stats = $recon->reconcile();

    echo date('Y-m-d H:i:s') . " - Done. "
        . "checked={$stats['checked']}, recovered={$stats['recovered']}, "
        . "failed_marked={$stats['failed_marked']}, still_pending={$stats['still_pending']}, "
        . "errors={$stats['errors']}\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Reconcile Payments Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_reconcile-payments',
        '[Cron] Exception: reconcile-payments',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

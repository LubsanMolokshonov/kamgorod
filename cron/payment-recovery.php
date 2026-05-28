#!/usr/bin/env php
<?php
/**
 * Cron Script: Payment Recovery Emails
 *
 * Recovery-письма по failed-заказам (брошенная корзина после TTL Yookassa).
 *
 * Recommended cron schedule: every 15 minutes
 * Crontab: every 15 minutes — docker exec pedagogy_web php /var/www/html/cron/payment-recovery.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/PaymentRecoveryChain.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('payment-recovery');

$lockFile = '/tmp/payment_recovery_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_payment_recovery',
            '[Cron] Удалён зависший lock: payment-recovery',
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
    echo date('Y-m-d H:i:s') . " - Starting payment recovery processing...\n";

    $chain = new PaymentRecoveryChain($db);

    $scheduled = $chain->scheduleNewCandidates();
    echo date('Y-m-d H:i:s') . " - Scheduled new: {$scheduled}\n";

    $results = $chain->processPending();
    echo date('Y-m-d H:i:s') . " - Touch1. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    $results2 = $chain->processSecondTouch();
    echo date('Y-m-d H:i:s') . " - Touch2. Sent: {$results2['sent']}, Failed: {$results2['failed']}, Skipped: {$results2['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'payment_recovery_email_log',
        'payment_recovery_mass_failures',
        'recovery-письма (PaymentRecoveryChain)'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Payment Recovery Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_payment-recovery',
        '[Cron] Exception: payment-recovery',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

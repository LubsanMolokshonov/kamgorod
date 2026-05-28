#!/usr/bin/env php
<?php
/**
 * Cron Script: Email-цепочки генератора материалов ФОП
 *
 * Треки: onboarding (новички), balance (низкий/нулевой баланс), reactivation (простаивающие).
 * Планировщики сканеров (balance/reactivation) запускаются раз в сутки (гейт по дате-файлу),
 * рассылка очереди — каждый прогон.
 *
 * Crontab: every 5 minutes — php /path/to/cron/process-material-emails.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/MaterialTokenEmailChain.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-material-emails');

$lockFile = '/tmp/material_email_chain_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_material_emails',
            '[Cron] Удалён зависший lock: material_emails',
            ['lock_file' => $lockFile, 'age_sec' => $lockAge],
            'warning'
        );
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance running. Exit.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    $chain = new MaterialTokenEmailChain($db);

    // Сканеры balance/reactivation — раз в сутки (гейт по дате в /tmp)
    $planMarker = '/tmp/material_email_plan_date.txt';
    $today = date('Y-m-d');
    $lastPlan = file_exists($planMarker) ? trim((string)file_get_contents($planMarker)) : '';
    if ($lastPlan !== $today) {
        $balPlanned = $chain->planBalanceCampaign();
        $rePlanned  = $chain->planReactivation();
        file_put_contents($planMarker, $today);
        echo date('Y-m-d H:i:s') . " - Planned: balance {$balPlanned}, reactivation {$rePlanned}\n";
    }

    $results = $chain->processPendingEmails();
    echo date('Y-m-d H:i:s') . " - Done. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'material_email_log',
        'material_email_mass_failures',
        'цепочка материалов ФОП'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Material Email Chain Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-material-emails',
        '[Cron] Exception: process-material-emails',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

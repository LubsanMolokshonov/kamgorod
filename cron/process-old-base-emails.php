#!/usr/bin/env php
<?php
/**
 * Cron: рассылки по старой базе (old_base_campaigns).
 * Crontab: every 5 minutes — php /path/to/cron/process-old-base-emails.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/OldBaseCampaignProcessor.php';

if (class_exists('TelegramNotifier')) {
    TelegramNotifier::registerFatalHandler('process-old-base-emails');
}

$lockFile = '/tmp/old_base_email_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance running. Exit.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

try {
    echo date('Y-m-d H:i:s') . " - Processing old-base email campaigns...\n";

    $processor = new OldBaseCampaignProcessor($db);
    $r = $processor->processAll();

    echo date('Y-m-d H:i:s')
        . " - Done. Campaigns: {$r['campaigns']}, "
        . "Sent: {$r['sent']}, Failed: {$r['failed']}, Skipped: {$r['skipped']}\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Old-base email cron error: " . $e->getMessage());

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

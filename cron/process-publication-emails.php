#!/usr/bin/env php
<?php
/**
 * Cron Script: Process Publication Email Chains
 *
 * Триггерные email-цепочки для публикаций в журнале:
 * - Напоминание об оформлении сертификата (cert_reminder)
 * - Напоминание об оплате (payment_reminder)
 * - Предложение повторной публикации при отклонении (rejected_retry)
 *
 * Recommended cron schedule: every 5 minutes
 * Crontab: every 5 min - php /path/to/cron/process-publication-emails.php
 *
 * For Docker:
 * Crontab: every 5 min - docker exec pedagogy_web php /var/www/html/cron/process-publication-emails.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/PublicationEmailChain.php';

// Lock file to prevent overlapping runs
$lockFile = '/tmp/publication_email_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    echo date('Y-m-d H:i:s') . " - Starting publication email chain processing...\n";

    $chain = new PublicationEmailChain($db);
    $results = $chain->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Publication Email Cron Error: " . $e->getMessage());

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

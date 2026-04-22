#!/usr/bin/env php
<?php
/**
 * Cron Script: Process Webinar Email Queue
 *
 * Processes pending email notifications for webinar registrations.
 * Sends: confirmation, 24h reminder, 1h broadcast link, follow-up emails.
 *
 * Recommended cron schedule: every 5 minutes
 *
 * For Docker (add to host crontab):
 * [every 5 min] docker exec pedagogy_web php /var/www/html/cron/process-webinar-emails.php
 *
 * Or inside container:
 * [every 5 min] php /var/www/html/cron/process-webinar-emails.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

// Set unlimited execution time for cron
set_time_limit(0);

// Define base path
define('BASE_PATH', dirname(__DIR__));

// Load configuration
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/WebinarEmailJourney.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-webinar-emails');

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/webinar_email_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $lockAge = time() - $lockTime;
    // If lock is older than 10 minutes, remove it (stale lock)
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_webinar_emails',
            '[Cron] Удалён зависший lock: webinar_emails',
            ['lock_file' => $lockFile, 'age_sec' => $lockAge],
            'warning'
        );
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}

// Create lock
file_put_contents($lockFile, getmypid());

try {
    echo date('Y-m-d H:i:s') . " - Starting webinar email processing...\n";

    $journey = new WebinarEmailJourney($db);

    // Backfill missing touchpoints for existing registrations
    $backfilled = $journey->backfillMissingTouchpoints();
    if ($backfilled > 0) {
        echo date('Y-m-d H:i:s') . " - Backfilled {$backfilled} missing email entries.\n";
    }

    $results = $journey->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'email_journey_log',
        'webinar_email_mass_failures',
        'цепочка вебинаров'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Webinar Email Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-webinar-emails',
        '[Cron] Exception: process-webinar-emails',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

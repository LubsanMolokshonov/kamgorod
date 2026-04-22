#!/usr/bin/env php
<?php
/**
 * Cron Script: Process Email Journey Queue
 *
 * Processes pending email notifications for unpaid registrations.
 *
 * Recommended cron schedule: every 5 minutes
 * Crontab: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * php process-email-journey.php
 *
 * For Docker:
 * Crontab: 0,5,10,15,20,25,30,35,40,45,50,55 * * * * docker exec pedagogy_web php /var/www/html/cron/process-email-journey.php
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
require_once BASE_PATH . '/classes/EmailJourney.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-email-journey');

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/email_journey_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $lockAge = time() - $lockTime;
    // If lock is older than 10 minutes, remove it (stale lock)
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_email_journey',
            '[Cron] Удалён зависший lock: email_journey',
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
    echo date('Y-m-d H:i:s') . " - Starting email journey processing...\n";

    $journey = new EmailJourney($db);
    $results = $journey->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'email_journey_log',
        'journey_email_mass_failures',
        'цепочка регистраций (EmailJourney)'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Email Journey Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-email-journey',
        '[Cron] Exception: process-email-journey',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

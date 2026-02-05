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

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/webinar_email_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    // If lock is older than 10 minutes, remove it (stale lock)
    if (time() - $lockTime > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
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
    $results = $journey->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Webinar Email Cron Error: " . $e->getMessage());

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

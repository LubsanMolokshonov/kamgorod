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

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/email_journey_cron.lock';

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
    echo date('Y-m-d H:i:s') . " - Starting email journey processing...\n";

    $journey = new EmailJourney($db);
    $results = $journey->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Email Journey Cron Error: " . $e->getMessage());

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

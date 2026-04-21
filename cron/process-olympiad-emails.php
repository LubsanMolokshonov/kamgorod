#!/usr/bin/env php
<?php
// Cron Script: Process Olympiad Email Chain Queue
// Обработка очереди email-напоминаний для неоплаченных дипломов олимпиад.
// Recommended cron schedule: every 5 minutes

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
require_once BASE_PATH . '/classes/OlympiadEmailChain.php';

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/olympiad_email_chain_cron.lock';

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
    echo date('Y-m-d H:i:s') . " - Starting olympiad email chain processing...\n";

    $chain = new OlympiadEmailChain($db);

    // Обработка дипломной цепочки (1ч/24ч/3д/7д)
    $results = $chain->processPendingEmails();
    echo date('Y-m-d H:i:s') . " - Diploma chain: Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    // Обработка quiz-писем (регистрация, результаты теста)
    $quizResults = $chain->processQuizEmails();
    echo date('Y-m-d H:i:s') . " - Quiz emails: Sent: {$quizResults['sent']}, Failed: {$quizResults['failed']}, Skipped: {$quizResults['skipped']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Olympiad Email Chain Cron Error: " . $e->getMessage());

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

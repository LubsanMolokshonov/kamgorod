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
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-olympiad-emails');

// Create lock file to prevent overlapping runs
$lockFile = '/tmp/olympiad_email_chain_cron.lock';

if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    $lockAge = time() - $lockTime;
    // If lock is older than 10 minutes, remove it (stale lock)
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock file.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_olympiad_emails',
            '[Cron] Удалён зависший lock: olympiad_emails',
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
    echo date('Y-m-d H:i:s') . " - Starting olympiad email chain processing...\n";

    $chain = new OlympiadEmailChain($db);

    // Обработка дипломной цепочки (1ч/24ч/3д/7д)
    $results = $chain->processPendingEmails();
    echo date('Y-m-d H:i:s') . " - Diploma chain: Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    // Обработка quiz-писем (регистрация, результаты теста)
    $quizResults = $chain->processQuizEmails();
    echo date('Y-m-d H:i:s') . " - Quiz emails: Sent: {$quizResults['sent']}, Failed: {$quizResults['failed']}, Skipped: {$quizResults['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'olympiad_email_log',
        'olympiad_email_mass_failures',
        'цепочка олимпиад'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Olympiad Email Chain Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-olympiad-emails',
        '[Cron] Exception: process-olympiad-emails',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    // Remove lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

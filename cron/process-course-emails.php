#!/usr/bin/env php
<?php
/**
 * Cron Script: Email-цепочка дожима курсов
 *
 * Обрабатывает очередь email для неоплаченных записей на курсы.
 * 6 писем: welcome (0), 15мин, 1ч, 24ч, 2д, 3д.
 *
 * Crontab: every 5 minutes — php /path/to/cron/process-course-emails.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/classes/CoursePriceAB.php';

// Lock
$lockFile = '/tmp/course_email_chain_cron.lock';

if (file_exists($lockFile)) {
    if (time() - filemtime($lockFile) > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance running. Exit.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

try {
    echo date('Y-m-d H:i:s') . " - Processing course email chain...\n";

    $chain = new CourseEmailChain($db);
    $results = $chain->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Done. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Course Email Chain Cron Error: " . $e->getMessage());

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

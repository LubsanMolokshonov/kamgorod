#!/usr/bin/env php
<?php
/**
 * Cron Script: Email-цепочка дожима курсов
 *
 * Обрабатывает очередь email для неоплаченных записей на курсы.
 * Touchpoints: welcome (0), 15мин, 1ч, 90мин (bitrix_only), 24ч, 2д, 3д.
 * Мониторинг ЦДО: деактивация email-цепочки при прогрессе сделки дальше «Подготовка документов».
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
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-course-emails');

// Lock
$lockFile = '/tmp/course_email_chain_cron.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
        TelegramNotifier::instance($db)->alert(
            'cron_stale_lock_course_emails',
            '[Cron] Удалён зависший lock: course_emails',
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
    echo date('Y-m-d H:i:s') . " - Processing course email chain...\n";

    $chain = new CourseEmailChain($db);

    // Проверить сделки в ЦДО — деактивировать email при прогрессе дальше «Подготовка документов»
    $cdoResults = $chain->checkCdoDealsAndCancelEmails();
    if ($cdoResults['cancelled'] > 0) {
        echo date('Y-m-d H:i:s') . " - CDO check: {$cdoResults['checked']} checked, {$cdoResults['cancelled']} cancelled\n";
    }

    $results = $chain->processPendingEmails();

    echo date('Y-m-d H:i:s') . " - Done. Sent: {$results['sent']}, Failed: {$results['failed']}, Skipped: {$results['skipped']}\n";

    TelegramNotifier::instance($db)->checkEmailFailureThreshold(
        'course_email_log',
        'course_email_mass_failures',
        'цепочка курсов'
    );

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Course Email Chain Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-course-emails',
        '[Cron] Exception: process-course-emails',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

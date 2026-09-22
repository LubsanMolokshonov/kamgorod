#!/usr/bin/env php
<?php
declare(strict_types=1);

if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/MaxCourseRecommendationChain.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-max-course-recommendations');

$send = in_array('--send', array_slice($argv, 1), true);
$chain = new MaxCourseRecommendationChain($db);

if (!$send) {
    echo "DRY-RUN: сообщения не отправляются. Для обработки очереди добавьте --send.\n";
    foreach ($chain->getStatusCounts() as $status => $count) {
        echo $status . ': ' . $count . "\n";
    }
    exit(0);
}

if (!MAX_COURSE_RECOMMENDATION_ACTIVE || !CHATPUSH_ACTIVE || CHATPUSH_API_TOKEN === '' || CHATPUSH_CALLBACK_SECRET === '') {
    echo date('Y-m-d H:i:s') . " - MAX course recommendation disabled, ChatPush unavailable, or STOP callback is not configured.\n";
    exit(0);
}

$hour = (int)date('G');
if ($hour < 10 || $hour >= 20) {
    echo date('Y-m-d H:i:s') . " - Quiet hours; processing deferred until 10:00–20:00 MSK.\n";
    exit(0);
}

$lock = fopen('/tmp/fgos-max-course-recommendations.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo date('Y-m-d H:i:s') . " - Another instance is running.\n";
    exit(0);
}

try {
    $startedAt = microtime(true);
    echo date('Y-m-d H:i:s') . ' - Starting MAX course recommendations; batch=' . MAX_COURSE_RECOMMENDATION_BATCH . "\n";
    $scheduled = $chain->scheduleMissingRecent();
    $result = $chain->processPending();
    $processed = array_sum($result);
    echo date('Y-m-d H:i:s') . ' - Done. Sent: ' . $result['sent']
        . ', failed: ' . $result['failed'] . ', skipped: ' . $result['skipped']
        . ', retried: ' . $result['retried']
        . ', scheduled repair: ' . $scheduled . ', processed: ' . $processed
        . ', duration: ' . number_format(microtime(true) - $startedAt, 2, '.', '') . "s\n";

    $failedBacklog = $chain->getRecentFailedCount(24);
    if ($failedBacklog > 0) {
        TelegramNotifier::instance($db)->alert(
            'max_course_recommendation_failures',
            '[MAX] Ошибки отложенных рекомендаций курсов',
            ['failed_in_run' => $result['failed'], 'failed_last_24h' => $failedBacklog, 'batch' => MAX_COURSE_RECOMMENDATION_BATCH],
            'warning'
        );
    }
} catch (Throwable $e) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' - ERROR: ' . $e->getMessage() . "\n");
    TelegramNotifier::instance($db)->alert(
        'cron_exception_max_course_recommendations',
        '[Cron] Exception: MAX course recommendations',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );
    exit(1);
} finally {
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

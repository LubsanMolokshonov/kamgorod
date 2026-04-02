#!/usr/bin/env php
<?php
/**
 * Cron Script: Отложенная синхронизация записей на курсы с Bitrix24
 *
 * Через 10 минут после записи, если bitrix_lead_id пустой:
 * - status = 'paid' → сделка на этапе "Оплата на сайте" (BITRIX24_COURSE_STAGE_PAID)
 * - status != 'paid' → сделка на этапе "Новая" (BITRIX24_COURSE_STAGE_NEW)
 *
 * Crontab (every 5 min): docker exec pedagogy_web php /var/www/html/cron/process-course-bitrix.php
 */

// Prevent web access
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Course.php';
require_once BASE_PATH . '/classes/Bitrix24Integration.php';

// Lock file
$lockFile = '/tmp/course_bitrix_cron.lock';

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

$BATCH_SIZE = 50;
$MAX_ATTEMPTS = 3;
$DELAY_MINUTES = 10;

try {
    echo date('Y-m-d H:i:s') . " - Starting course Bitrix24 sync...\n";

    $bitrix = new Bitrix24Integration();
    if (!$bitrix->isConfigured()) {
        echo date('Y-m-d H:i:s') . " - Bitrix24 not configured. Exiting.\n";
        exit(0);
    }

    $dbObj = new Database($db);
    $courseObj = new Course($db);

    $stageNew = defined('BITRIX24_COURSE_STAGE_NEW') ? BITRIX24_COURSE_STAGE_NEW : 'C108:NEW';
    $stagePaid = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:EXECUTING';

    // Выборка записей старше 10 минут без bitrix_lead_id
    $enrollments = $dbObj->query(
        "SELECT ce.*, c.title AS course_title, c.program_type, c.hours, c.price, c.learning_format
         FROM course_enrollments ce
         JOIN courses c ON c.id = ce.course_id
         WHERE ce.bitrix_lead_id IS NULL
           AND ce.bitrix_attempts < ?
           AND ce.created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE)
         ORDER BY ce.created_at ASC
         LIMIT ?",
        [$MAX_ATTEMPTS, $DELAY_MINUTES, $BATCH_SIZE]
    );

    $sent = 0;
    $failed = 0;
    $skipped = 0;

    foreach ($enrollments as $enrollment) {
        try {
            // Транзакция с FOR UPDATE для защиты от race condition
            $db->beginTransaction();

            $fresh = $dbObj->queryOne(
                "SELECT * FROM course_enrollments WHERE id = ? AND bitrix_lead_id IS NULL FOR UPDATE",
                [$enrollment['id']]
            );

            if (!$fresh) {
                // Уже обработана (webhook успел)
                $db->commit();
                $skipped++;
                continue;
            }

            // Определяем этап: если оплачен — "Оплата на сайте", иначе — "Новая"
            $stage = ($fresh['status'] === 'paid') ? $stagePaid : $stageNew;

            $course = $courseObj->getById($fresh['course_id']);
            if (!$course) {
                $db->commit();
                $skipped++;
                continue;
            }

            $dealId = $bitrix->createCourseDeal([
                'full_name' => $fresh['full_name'],
                'email' => $fresh['email'],
                'phone' => $fresh['phone'],
                'utm_source' => $fresh['utm_source'] ?? '',
                'utm_medium' => $fresh['utm_medium'] ?? '',
                'utm_campaign' => $fresh['utm_campaign'] ?? '',
                'utm_content' => $fresh['utm_content'] ?? '',
                'utm_term' => $fresh['utm_term'] ?? '',
                'ym_uid' => $fresh['ym_uid'] ?? '',
                'source_page' => $fresh['source_page'] ?? '',
            ], $course, $stage);

            if ($dealId) {
                $dbObj->update('course_enrollments', [
                    'bitrix_lead_id' => $dealId,
                    'bitrix_stage' => $stage,
                ], 'id = ?', [$fresh['id']]);
                $sent++;
                echo date('Y-m-d H:i:s') . " - Enrollment #{$fresh['id']}: deal #{$dealId} (stage: {$stage})\n";
            } else {
                // API вернул null — инкрементируем attempts
                $dbObj->execute(
                    "UPDATE course_enrollments SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
                    [$fresh['id']]
                );
                $failed++;
                echo date('Y-m-d H:i:s') . " - Enrollment #{$fresh['id']}: Bitrix24 API failed\n";
            }

            $db->commit();

        } catch (Exception $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            // Инкрементируем attempts вне транзакции
            try {
                $dbObj->execute(
                    "UPDATE course_enrollments SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
                    [$enrollment['id']]
                );
            } catch (Exception $ignore) {}

            $failed++;
            echo date('Y-m-d H:i:s') . " - Enrollment #{$enrollment['id']} error: " . $e->getMessage() . "\n";
            error_log("Course Bitrix24 sync error (enrollment #{$enrollment['id']}): " . $e->getMessage());
        }
    }

    echo date('Y-m-d H:i:s') . " - Completed. Sent: {$sent}, Failed: {$failed}, Skipped: {$skipped}\n";

} catch (Exception $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Course Bitrix24 Cron Error: " . $e->getMessage());

} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

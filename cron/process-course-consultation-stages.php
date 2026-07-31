#!/usr/bin/env php
<?php
/**
 * Cron: сервисный учёт по заявкам на консультацию по курсам в Bitrix24.
 *
 * Автопродвижение этапов по времени (15/60/90 мин) ОТКЛЮЧЕНО 2026-07-31 —
 * переводы сделки между этапами теперь делают только менеджеры вручную.
 * Скрипт продолжает делать сервисный учёт, не связанный со сменой этапа:
 *  - Retry-создание сделки для консультаций без bitrix_lead_id (fallback).
 *  - Проверка ЦДО: если сделка (переведённая менеджером на этап
 *    «менеджер») ушла дальше «Подготовки документов», помечаем
 *    status='processed' (consultations) / 'enrolled' (course_enrollments).
 *    Создание сделки для course_enrollments — в process-course-bitrix.php.
 *
 * Crontab (каждые 5 минут):
 *   docker exec pedagogy_web php /var/www/html/cron/process-course-consultation-stages.php
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

set_time_limit(0);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Bitrix24Integration.php';

$lockFile = '/tmp/course_consultation_stages_cron.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime > 600) {
        unlink($lockFile);
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

$MAX_AGE_DAYS   = 7;
$MAX_ATTEMPTS   = 3;
$BATCH_SIZE     = 50;

$STAGE_NEW     = defined('BITRIX24_COURSE_STAGE_NEW')     ? BITRIX24_COURSE_STAGE_NEW     : 'C108:NEW';
$STAGE_15MIN   = defined('BITRIX24_COURSE_STAGE_15MIN')   ? BITRIX24_COURSE_STAGE_15MIN   : 'C108:UC_HWWIFQ';
$STAGE_1H      = defined('BITRIX24_COURSE_STAGE_1H')      ? BITRIX24_COURSE_STAGE_1H      : 'C108:UC_1YOFLO';
$STAGE_MANAGER = defined('BITRIX24_COURSE_STAGE_MANAGER') ? BITRIX24_COURSE_STAGE_MANAGER : 'C108:UC_DLXNLQ';

$CDO_PIPELINE_ID = defined('BITRIX24_CDO_PIPELINE_ID')    ? BITRIX24_CDO_PIPELINE_ID    : 4;
$CDO_DOCS_SORT   = defined('BITRIX24_CDO_STAGE_DOCS_SORT') ? BITRIX24_CDO_STAGE_DOCS_SORT : 80;

$logFile = BASE_PATH . '/logs/course-consultation-stages.log';
if (!file_exists(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
function log_line($msg) {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    error_log($line, 3, $logFile);
}

try {
    $bitrix = new Bitrix24Integration();
    if (!$bitrix->isConfigured()) {
        log_line('Bitrix24 not configured. Exiting.');
        exit(0);
    }

    $dbObj = new Database($db);

    // ─────────────────────────────────────────────────────────────
    // 1) Retry: создать сделку для консультаций без bitrix_lead_id
    // ─────────────────────────────────────────────────────────────
    $pending = $dbObj->query(
        "SELECT * FROM course_consultations
         WHERE bitrix_lead_id IS NULL
           AND bitrix_attempts < ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
           AND created_at <= DATE_SUB(NOW(), INTERVAL 1 MINUTE)
         ORDER BY created_at ASC
         LIMIT ?",
        [$MAX_ATTEMPTS, $MAX_AGE_DAYS, $BATCH_SIZE]
    );

    foreach ($pending as $c) {
        try {
            $dealId = $bitrix->createCourseConsultationDeal([
                'phone'        => $c['phone'],
                'course_title' => $c['course_title'] ?? '',
                'utm_source'   => $c['utm_source']   ?? '',
                'utm_medium'   => $c['utm_medium']   ?? '',
                'utm_campaign' => $c['utm_campaign'] ?? '',
                'utm_content'  => $c['utm_content']  ?? '',
                'utm_term'     => $c['utm_term']     ?? '',
                'ym_uid'       => $c['ym_uid']       ?? '',
                'source_page'  => $c['source_page']  ?? '',
            ]);

            if ($dealId) {
                $dbObj->update('course_consultations', [
                    'bitrix_lead_id' => $dealId,
                    'bitrix_stage' => $STAGE_NEW,
                    'bitrix_stage_updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$c['id']]);
                log_line("RETRY_CREATE | Consultation #{$c['id']} → deal #{$dealId}");
            } else {
                $dbObj->execute(
                    "UPDATE course_consultations SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
                    [$c['id']]
                );
                log_line("RETRY_CREATE_FAIL | Consultation #{$c['id']}");
            }
        } catch (Exception $e) {
            $dbObj->execute(
                "UPDATE course_consultations SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
                [$c['id']]
            );
            log_line("RETRY_CREATE_ERROR | Consultation #{$c['id']} | " . $e->getMessage());
        }
    }

    // 2) Автопродвижение этапов по времени ОТКЛЮЧЕНО 2026-07-31 —
    //    переводы между этапами сделки делают только менеджеры вручную
    //    в Bitrix24. $moved оставлен для лога DONE ниже.
    $moved = 0;

    // ─────────────────────────────────────────────────────────────
    // 3) Проверка ЦДО: если сделка ушла в ЦДО и прошла
    //    «Подготовку документов» — помечаем processed.
    // ─────────────────────────────────────────────────────────────
    $onManager = $dbObj->query(
        "SELECT id, bitrix_lead_id FROM course_consultations
         WHERE status = 'new'
           AND bitrix_lead_id IS NOT NULL
           AND bitrix_stage = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$STAGE_MANAGER, $MAX_AGE_DAYS]
    );

    $cdoStages = [];
    if (!empty($onManager)) {
        $url = rtrim(defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '', '/');
        if ($url !== '') {
            $resp = @file_get_contents($url . '/crm.dealcategory.stage.list.json?' . http_build_query(['id' => $CDO_PIPELINE_ID]));
            if ($resp !== false) {
                $data = json_decode($resp, true);
                foreach (($data['result'] ?? []) as $stage) {
                    $cdoStages[$stage['STATUS_ID']] = (int)$stage['SORT'];
                }
            }
        }
    }

    $cdoClosed = 0;
    foreach ($onManager as $r) {
        try {
            $deal = $bitrix->getDeal($r['bitrix_lead_id']);
            if (!$deal) continue;

            $dealStage    = $deal['STAGE_ID']    ?? '';
            $dealCategory = $deal['CATEGORY_ID'] ?? '';

            if ((int)$dealCategory !== (int)$CDO_PIPELINE_ID) continue;

            $sort = $cdoStages[$dealStage] ?? 0;
            if ($sort > $CDO_DOCS_SORT) {
                $dbObj->update('course_consultations', [
                    'status' => 'processed',
                    'bitrix_stage' => $dealStage,
                    'bitrix_stage_updated_at' => date('Y-m-d H:i:s'),
                ], 'id = ?', [$r['id']]);
                $cdoClosed++;
                log_line("CDO_PROCESSED | Consultation #{$r['id']} | Deal #{$r['bitrix_lead_id']} at {$dealStage}");
            }
        } catch (Exception $e) {
            log_line("CDO_CHECK_ERROR | Consultation #{$r['id']} | " . $e->getMessage());
        }
    }

    // ─────────────────────────────────────────────────────────────
    // 4) Автопродвижение этапов для course_enrollments по времени
    //    ОТКЛЮЧЕНО 2026-07-31 — переводы делают только менеджеры вручную.
    //    bitrix_stage для course_enrollments по-прежнему актуализируется
    //    независимо через cron/sync-course-deal-stages.php (каждые 30 мин,
    //    подтягивает реальный STAGE_ID из Bitrix), так что секция 5 ниже
    //    продолжает находить сделки, доведённые менеджером до STAGE_MANAGER.
    $enrollMoved = 0;

    // ─────────────────────────────────────────────────────────────
    // 5) Проверка ЦДО для course_enrollments: если сделка ушла в ЦДО
    //    и прошла «Подготовку документов» — статус enrolled.
    // ─────────────────────────────────────────────────────────────
    $enrollOnManager = $dbObj->query(
        "SELECT id, bitrix_lead_id FROM course_enrollments
         WHERE status = 'new'
           AND bitrix_lead_id IS NOT NULL
           AND bitrix_stage = ?
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
        [$STAGE_MANAGER, $MAX_AGE_DAYS]
    );

    if (!empty($enrollOnManager) && empty($cdoStages)) {
        $url = rtrim(defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '', '/');
        if ($url !== '') {
            $resp = @file_get_contents($url . '/crm.dealcategory.stage.list.json?' . http_build_query(['id' => $CDO_PIPELINE_ID]));
            if ($resp !== false) {
                $data = json_decode($resp, true);
                foreach (($data['result'] ?? []) as $stage) {
                    $cdoStages[$stage['STATUS_ID']] = (int)$stage['SORT'];
                }
            }
        }
    }

    $enrollCdoClosed = 0;
    foreach ($enrollOnManager as $r) {
        try {
            $deal = $bitrix->getDeal($r['bitrix_lead_id']);
            if (!$deal) continue;

            $dealStage    = $deal['STAGE_ID']    ?? '';
            $dealCategory = $deal['CATEGORY_ID'] ?? '';

            if ((int)$dealCategory !== (int)$CDO_PIPELINE_ID) continue;

            $sort = $cdoStages[$dealStage] ?? 0;
            if ($sort > $CDO_DOCS_SORT) {
                $dbObj->update('course_enrollments', [
                    'status' => 'enrolled',
                    'bitrix_stage' => $dealStage,
                ], 'id = ?', [$r['id']]);
                $enrollCdoClosed++;
                log_line("CDO_PROCESSED | Enrollment #{$r['id']} | Deal #{$r['bitrix_lead_id']} at {$dealStage}");
            }
        } catch (Exception $e) {
            log_line("CDO_CHECK_ERROR | Enrollment #{$r['id']} | " . $e->getMessage());
        }
    }

    log_line("DONE | Moved: {$moved}, CDO_processed: {$cdoClosed}, Retry_pending: " . count($pending)
        . " | Enroll_moved: {$enrollMoved}, Enroll_CDO: {$enrollCdoClosed}");

} catch (Exception $e) {
    log_line('FATAL: ' . $e->getMessage());
    error_log('Course consultation stages cron error: ' . $e->getMessage());
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

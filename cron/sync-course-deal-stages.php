#!/usr/bin/env php
<?php
/**
 * Cron: sync актуального этапа сделки из Bitrix24 в course_enrollments.
 *
 * Зачем: часть сделок в воронке «Курсы» (C108) сервер НЕ двигает — это
 * заявки на рассрочку (status='installment_requested') и обычные «зависшие»
 * сделки, которые менеджер вручную закрывает в B24. Чтобы админка
 * (admin/courses/) корректно показывала paid_count, нужно подтянуть
 * результат обратно: STAGE_ID → bitrix_stage, при «Оплаченная сделка» →
 * status='paid', при LOSE → status='cancelled'.
 *
 * Что НЕ делает: не двигает сделки в Битрикс — двигают только Битрикс-роботы.
 *
 * Crontab (каждые 30 минут):
 *   *\/30 * * * * docker exec pedagogy_web php /var/www/html/cron/sync-course-deal-stages.php
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

$lockFile = '/tmp/sync_course_deal_stages_cron.lock';
if (file_exists($lockFile)) {
    $lockTime = filemtime($lockFile);
    if (time() - $lockTime > 1800) {
        unlink($lockFile);
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

$MAX_AGE_DAYS  = 90;
$BATCH_SIZE    = 200;
$COURSE_PIPELINE = defined('BITRIX24_COURSE_PIPELINE_ID') ? (int)BITRIX24_COURSE_PIPELINE_ID : 108;
$STAGE_PAID    = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:UC_8RO3WZ';

$logFile = BASE_PATH . '/logs/sync-course-deal-stages.log';
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

    // 1) LOSE-этапы воронки 108 — один запрос за прогон.
    $loseStages = [];
    $url = rtrim(defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '', '/');
    if ($url !== '') {
        $resp = @file_get_contents(
            $url . '/crm.dealcategory.stage.list.json?' . http_build_query(['id' => $COURSE_PIPELINE])
        );
        if ($resp !== false) {
            $data = json_decode($resp, true);
            foreach (($data['result'] ?? []) as $stage) {
                // SEMANTIC: 'S' (in progress), 'P' (won/success), 'L' (lose/fail)
                if (($stage['SEMANTICS'] ?? $stage['SEMANTIC'] ?? '') === 'L') {
                    $loseStages[$stage['STATUS_ID']] = true;
                }
            }
        }
    }

    // 2) Выборка enrollments под опрос: те, по которым нет оплаты с сайта.
    $rows = $dbObj->query(
        "SELECT id, bitrix_lead_id, status, bitrix_stage, bitrix_stage_updated_at
         FROM course_enrollments
         WHERE bitrix_lead_id IS NOT NULL
           AND status IN ('new', 'installment_requested')
           AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
         ORDER BY COALESCE(bitrix_stage_updated_at, '1970-01-01') ASC
         LIMIT ?",
        [$MAX_AGE_DAYS, $BATCH_SIZE]
    );

    $synced = 0;
    $paid   = 0;
    $lost   = 0;
    $errors = 0;

    foreach ($rows as $r) {
        try {
            $deal = $bitrix->getDeal($r['bitrix_lead_id']);
            if (!$deal) {
                $errors++;
                log_line("SYNC_MISS | Enrollment #{$r['id']} | Deal #{$r['bitrix_lead_id']} not found");
                continue;
            }

            $stageId  = (string)($deal['STAGE_ID']    ?? '');
            $category = (int)($deal['CATEGORY_ID'] ?? -1);
            $oldStage = $r['bitrix_stage'] ?? '';

            $update = [
                'bitrix_stage' => $stageId,
                'bitrix_stage_updated_at' => date('Y-m-d H:i:s'),
            ];

            $statusChange = null;
            if ($category === $COURSE_PIPELINE) {
                if ($stageId === $STAGE_PAID && $r['status'] !== 'paid') {
                    $update['status'] = 'paid';
                    $statusChange = 'paid';
                    $paid++;
                } elseif (isset($loseStages[$stageId]) && $r['status'] !== 'cancelled') {
                    $update['status'] = 'cancelled';
                    $statusChange = 'cancelled';
                    $lost++;
                }
            }

            $dbObj->update('course_enrollments', $update, 'id = ?', [$r['id']]);
            $synced++;

            if ($oldStage !== $stageId || $statusChange) {
                $statusPart = $statusChange ? " | status→{$statusChange}" : '';
                log_line("MOVE_SYNC | Enrollment #{$r['id']} | Deal #{$r['bitrix_lead_id']} | {$oldStage} → {$stageId}{$statusPart}");
            }
        } catch (Exception $e) {
            $errors++;
            log_line("SYNC_ERROR | Enrollment #{$r['id']} | Deal #{$r['bitrix_lead_id']} | " . $e->getMessage());
        }
    }

    log_line("DONE | Synced: {$synced} | Paid: {$paid} | Lost: {$lost} | Errors: {$errors}");

} catch (Exception $e) {
    log_line('FATAL: ' . $e->getMessage());
    error_log('Sync course deal stages cron error: ' . $e->getMessage());
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

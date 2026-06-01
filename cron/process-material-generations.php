#!/usr/bin/env php
<?php
/**
 * Cron Script: фоновая (async) генерация материалов ФОП.
 *
 * Берёт задачи material_generations со статусом 'pending', выполняет ИИ-генерацию
 * (LLM + методическая самопроверка, 60–200с на задачу) и переводит в done/failed.
 * Запускается двумя путями:
 *   1) on-demand: ajax/generate-material.php спавнит этот скрипт сразу после enqueue;
 *   2) fallback: crontab каждую минуту (на случай, если spawn не сработал).
 * Lock-файл + атомарный pending→running (внутри MaterialGenerator::runPending)
 * исключают двойную обработку одной задачи.
 *
 * Crontab: * * * * * php /path/to/cron/process-material-generations.php
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Material.php';
require_once BASE_PATH . '/classes/MaterialType.php';
require_once BASE_PATH . '/classes/UserTokens.php';
require_once BASE_PATH . '/classes/OpenRouterAIService.php';
require_once BASE_PATH . '/classes/MaterialGenerator.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-material-generations');

$lockFile = '/tmp/material_generations_cron.lock';
const MG_BATCH_LIMIT   = 5;     // задач за один прогон
const MG_STALE_LOCK    = 1800;  // сек — лок старше = зависший (5 задач × ~200с + буфер ≈ 1800с)
const MG_STUCK_RUNNING = 600;   // сек — задача в 'running' дольше = воркер умер (по started_at)

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > MG_STALE_LOCK) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Removed stale lock.\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance running. Exit.\n";
        exit(0);
    }
}

file_put_contents($lockFile, getmypid());

$dbw = new Database($db);

try {
    // 0. Recovery: задачи, зависшие в 'running' дольше MG_STUCK_RUNNING (воркер упал/убит),
    //    переводим в failed + возвращаем токены (если списывались в full-режиме).
    //    Считаем по started_at (момент старта обработки), а не created_at — иначе задача,
    //    долго простоявшая в очереди pending под нагрузкой, ложно опозналась бы как зависшая
    //    сразу при старте. Fallback на created_at для старых строк без started_at.
    $stuck = $dbw->query(
        "SELECT id, user_id, tokens_charged, input_params_json
         FROM material_generations
         WHERE status = 'running'
           AND COALESCE(started_at, created_at) < (NOW() - INTERVAL ? SECOND)",
        [MG_STUCK_RUNNING]
    );
    foreach ($stuck as $row) {
        $gid = (int)$row['id'];
        // Refund для full-режима (preview: tokens_charged = 0 → пропускаем).
        if ((int)$row['tokens_charged'] > 0 && $row['user_id'] !== null) {
            $params = json_decode((string)($row['input_params_json'] ?? '[]'), true);
            $txnId = (is_array($params) && isset($params['_charge_txn_id'])) ? (int)$params['_charge_txn_id'] : null;
            if ($txnId !== null) {
                try {
                    (new UserTokens($db))->refund(
                        (int)$row['user_id'],
                        (int)$row['tokens_charged'],
                        $txnId,
                        ['generation_id' => $gid, 'notes' => 'auto-refund on stuck-running recovery']
                    );
                } catch (Throwable $re) {
                    error_log("material-gen recovery refund failed (gid={$gid}): " . $re->getMessage());
                }
            }
        }
        $dbw->update('material_generations', [
            'status' => 'failed',
            'error_message' => 'timeout: воркер не завершил задачу (recovery)',
            'finished_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$gid]);
        echo date('Y-m-d H:i:s') . " - Recovered stuck running gid={$gid}\n";
    }

    // 1. Обработать pending-очередь.
    $pending = $dbw->query(
        "SELECT id FROM material_generations WHERE status = 'pending' ORDER BY created_at ASC LIMIT " . MG_BATCH_LIMIT
    );
    $processed = 0;
    foreach ($pending as $row) {
        $gid = (int)$row['id'];
        // Освежаем mtime лока, чтобы долгая легитимная обработка не считалась зависшей.
        @touch($lockFile);
        try {
            (new MaterialGenerator($db))->runPending($gid);
            $processed++;
        } catch (Throwable $e) {
            // runPending уже сам пишет failed/refund; здесь только логируем неожиданное.
            error_log("material-gen worker error (gid={$gid}): " . $e->getMessage());
        }
    }
    echo date('Y-m-d H:i:s') . " - Done. Processed: {$processed}, recovered: " . count($stuck) . "\n";

} catch (Throwable $e) {
    echo date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n";
    error_log("Material Generations Cron Error: " . $e->getMessage());
    TelegramNotifier::instance($db)->alert(
        'cron_exception_process-material-generations',
        '[Cron] Exception: process-material-generations',
        ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
        'critical'
    );
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

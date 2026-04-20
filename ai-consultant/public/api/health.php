<?php
require_once __DIR__ . '/../../src/bootstrap.php';

$status = ['status' => 'ok', 'service' => 'ai-consultant', 'time' => date('c')];

try {
    $pdo = ai_get_pdo();
    $pdo->query('SELECT 1')->fetch();
    $status['db'] = 'ok';
} catch (Throwable $e) {
    $status['status'] = 'degraded';
    $status['db'] = 'error';
}

$status['gpt_configured'] = AI_YANDEX_GPT_API_KEY !== '' && AI_YANDEX_GPT_FOLDER_ID !== '';

ai_json($status);

<?php
/**
 * AJAX: статус асинхронной генерации материала (polling).
 *
 * GET:
 *   - id (generation_id из material_generations)
 *
 * Ответ:
 *   { success: true, status: 'pending'|'running' }
 *   { success: true, status: 'done', redirect_url }
 *   { success: true, status: 'failed', error, code }
 *   { success: false, error } — нет доступа / не найдено
 *
 * Лёгкий и быстрый: читает одну строку по PK, сессию сразу закрывает (лока не держит).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../includes/material-tracking.php';

// user_id прочитан — сессия больше не нужна, снимаем лок.
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
session_write_close();

function respondStatus(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    respondStatus(['success' => false, 'error' => 'Не указан id'], 400);
}

$dbw = new Database($db);
$row = $dbw->queryOne(
    'SELECT id, user_id, funnel_session_id, status, output_material_id, error_message
     FROM material_generations WHERE id = ?',
    [$id]
);
if (!$row) {
    respondStatus(['success' => false, 'error' => 'Задача не найдена'], 404);
}

// Проверка владения (анти-IDOR): либо тот же пользователь, либо тот же funnel-cookie.
$ownerUserId = $row['user_id'] !== null ? (int)$row['user_id'] : null;
$funnelSid   = materialFunnelSessionId();
$ownedByUser   = $userId !== null && $ownerUserId === $userId;
$ownedByFunnel = !empty($row['funnel_session_id']) && hash_equals((string)$row['funnel_session_id'], $funnelSid);
if (!$ownedByUser && !$ownedByFunnel) {
    respondStatus(['success' => false, 'error' => 'Нет доступа'], 403);
}

$status = (string)$row['status'];

if ($status === 'done' && !empty($row['output_material_id'])) {
    $material = (new Material($db))->getById((int)$row['output_material_id']);
    if ($material && !empty($material['slug'])) {
        respondStatus([
            'success'      => true,
            'status'       => 'done',
            'redirect_url' => '/material/' . rawurlencode($material['slug']) . '/',
        ]);
    }
    // done, но материал не нашёлся — трактуем как сбой.
    respondStatus([
        'success' => true,
        'status'  => 'failed',
        'error'   => 'Материал не найден. Попробуйте сгенерировать заново.',
        'code'    => 'no_material',
    ]);
}

if ($status === 'failed') {
    // Понятная формулировка вместо технического error_message.
    $raw = (string)($row['error_message'] ?? '');
    $isAi = stripos($raw, 'openrouter') !== false
        || stripos($raw, 'ИИ вернул') !== false
        || stripos($raw, 'timeout') !== false
        || stripos($raw, 'пуст') !== false;
    $msg = $isAi
        ? 'Сервис ИИ временно недоступен. Токены, если списывались, возвращены. Попробуйте ещё раз.'
        : 'Не удалось сгенерировать материал. Попробуйте ещё раз или измените параметры.';
    respondStatus([
        'success' => true,
        'status'  => 'failed',
        'error'   => $msg,
        'code'    => $isAi ? 'ai_error' : 'failed',
    ]);
}

// pending или running
respondStatus([
    'success' => true,
    'status'  => $status === 'running' ? 'running' : 'pending',
]);

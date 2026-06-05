<?php
/**
 * AJAX-эндпоинт: сохранение понедельного расхода направления в direction_weekly_costs.
 * Метод: POST
 * Поля: csrf_token, week_start (YYYY-MM-DD, понедельник), direction (ключ DIRECTIONS), value (число ≥ 0)
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/DirectionAnalytics.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../includes/session.php';

Admin::verifySession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не поддерживается']);
    exit;
}

$token = $_POST['csrf_token'] ?? '';
if (!validateCSRFToken($token)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Невалидный CSRF-токен']);
    exit;
}

$weekStart = trim($_POST['week_start'] ?? '');
$direction = trim($_POST['direction'] ?? '');
$rawValue  = $_POST['value'] ?? '';
$rawValue  = str_replace([' ', ','], ['', '.'], (string)$rawValue);
$value     = $rawValue === '' ? 0.0 : (float)$rawValue;

try {
    $analytics = new DirectionAnalytics($db);
    $analytics->saveCost($weekStart, $direction, $value);
    echo json_encode(['success' => true, 'value' => $value]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (\Exception $e) {
    error_log('Directions save-cost error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Внутренняя ошибка']);
}

<?php
/**
 * AJAX: увеличение персонального суточного лимита превью-генераций материалов.
 * Кнопка «Увеличить лимит» в сообщении о достижении суточного лимита.
 * Прибавляет к users.material_daily_limit_bonus 300000 — фактически снимает
 * ограничение для пользователя. Доступно только залогиненным.
 *
 * POST: csrf
 * Ответ: { success, daily_limit } | { success:false, error, code }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

function imlRespond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    imlRespond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}
if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    imlRespond(['success' => false, 'error' => 'Сессия истекла, обновите страницу', 'code' => 'csrf'], 403);
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
if ($userId === null) {
    imlRespond(['success' => false, 'error' => 'Войдите, чтобы увеличить лимит', 'code' => 'unauthorized'], 401);
}

const LIMIT_INCREMENT = 300000;

try {
    $database = new Database($db);
    $database->execute(
        "UPDATE users SET material_daily_limit_bonus = material_daily_limit_bonus + ? WHERE id = ?",
        [LIMIT_INCREMENT, $userId]
    );
    $bonus = (int)$database->queryOne(
        "SELECT material_daily_limit_bonus FROM users WHERE id = ?",
        [$userId]
    )['material_daily_limit_bonus'];

    imlRespond(['success' => true, 'daily_limit' => 10 + $bonus]);
} catch (Throwable $e) {
    error_log('increase-material-limit: ' . $e->getMessage());
    imlRespond(['success' => false, 'error' => 'Не удалось увеличить лимит, попробуйте позже', 'code' => 'internal'], 500);
}

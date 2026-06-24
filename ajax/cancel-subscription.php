<?php
/**
 * AJAX: отключить автопродление подписки + отвязать карту (полный opt-out).
 *
 * POST:
 *   - csrf
 *
 * Доступ к подписке сохраняется до expires_at — отключается только дальнейшее списание.
 * Ответ: { success:true, changed:bool, message } | { success:false, error, code }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../includes/session.php';

function respond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    respond(['success' => false, 'error' => 'Войдите в аккаунт', 'code' => 'unauthorized'], 401);
}

if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    respond(['success' => false, 'error' => 'Сессия истекла. Обновите страницу.', 'code' => 'csrf'], 403);
}

try {
    $changed = (new SubscriptionService($db))->cancelAutoRenew((int)$userId);
    respond([
        'success' => true,
        'changed' => $changed,
        'message' => $changed
            ? 'Автопродление отключено. Подписка действует до конца оплаченного периода.'
            : 'Автопродление уже было отключено.',
    ]);
} catch (Throwable $e) {
    error_log('cancel-subscription error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Не удалось отключить автопродление. Попробуйте ещё раз.', 'code' => 'server'], 500);
}

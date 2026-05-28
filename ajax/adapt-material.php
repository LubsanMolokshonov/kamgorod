<?php
/**
 * AJAX: адаптация материала через ИИ.
 *
 * POST: csrf, source_text, instructions
 * Ответ:
 *   { success: true, result_text, tokens_left, tokens_charged }
 *   { success: false, error, code }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/OpenRouterAIService.php';
require_once __DIR__ . '/../classes/MaterialAdapter.php';

set_time_limit(120);

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
    respond(['success' => false, 'error' => 'Войдите, чтобы адаптировать материал', 'code' => 'unauthorized'], 401);
}
if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    respond(['success' => false, 'error' => 'Сессия истекла', 'code' => 'csrf'], 403);
}

$sourceText = (string)($_POST['source_text'] ?? '');
$instructions = (string)($_POST['instructions'] ?? '');

try {
    $adapter = new MaterialAdapter($db);
    $result = $adapter->adapt((int)$userId, $sourceText, $instructions);
    respond([
        'success' => true,
        'adaptation_id' => $result['adaptation_id'],
        'result_text' => $result['result_text'],
        'tokens_charged' => $result['tokens_charged'],
        'tokens_left' => (new UserTokens($db))->getBalance((int)$userId),
    ]);
} catch (NotEnoughTokensException $e) {
    respond([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'not_enough_tokens',
        'tokens_left' => (new UserTokens($db))->getBalance((int)$userId),
        'buy_url' => '/material-balance/',
    ], 402);
} catch (InvalidArgumentException $e) {
    respond(['success' => false, 'error' => $e->getMessage(), 'code' => 'invalid_input'], 400);
} catch (OpenRouterAIServiceException $e) {
    error_log('Adapter AI error: ' . $e->getMessage());
    respond([
        'success' => false,
        'error' => 'Сервис ИИ временно недоступен. Токены возвращены на счёт.',
        'code' => 'ai_error',
        'tokens_left' => (new UserTokens($db))->getBalance((int)$userId),
    ], 502);
} catch (Throwable $e) {
    error_log('Adapter unexpected error: ' . $e->getMessage());
    respond(['success' => false, 'error' => 'Внутренняя ошибка', 'code' => 'internal'], 500);
}

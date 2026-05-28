<?php
/**
 * AJAX: генерация материала через ИИ.
 *
 * POST:
 *   - csrf
 *   - type_slug (slug из material_types)
 *   - params[*] (subject, class, topic, duration, features, questions_count, slides_count, hours, program)
 *
 * Ответ:
 *   { success: true, material_id, material_slug, file_format, tokens_left, redirect_url }
 *   { success: false, error: 'message', code: 'not_enough_tokens'|'unauthorized'|'invalid_type'|'ai_error'|'internal' }
 *
 * Timeout: 120 секунд (генерация может занимать 15-60 сек).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/OpenRouterAIService.php';
require_once __DIR__ . '/../classes/MaterialGenerator.php';

set_time_limit(120);

function respond(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    respond(['success' => false, 'error' => 'Войдите, чтобы сгенерировать материал', 'code' => 'unauthorized'], 401);
}

$csrf = $_POST['csrf'] ?? '';
if (!validateCSRFToken($csrf)) {
    respond(['success' => false, 'error' => 'Сессия истекла, обновите страницу', 'code' => 'csrf'], 403);
}

$typeSlug = trim((string)($_POST['type_slug'] ?? ''));
if ($typeSlug === '') {
    respond(['success' => false, 'error' => 'Не указан тип материала', 'code' => 'invalid_type'], 400);
}

// Параметры формы — пропускаем только текстовые поля, известные шаблонам
$allowedParamKeys = [
    'subject', 'class', 'topic', 'duration', 'features',
    'program', 'questions_count', 'slides_count', 'hours',
    'audience_category_ids', 'audience_type_ids', 'specialization_ids',
];
$params = [];
foreach ($allowedParamKeys as $key) {
    if (isset($_POST[$key])) {
        $value = $_POST[$key];
        // Текстовые поля ограничиваем по длине, чтобы не раздуть промпт
        if (is_string($value)) {
            $params[$key] = mb_substr(trim($value), 0, 1000);
        } else {
            $params[$key] = $value;
        }
    }
}

try {
    $generator = new MaterialGenerator($db);
    $result = $generator->generate((int)$userId, $typeSlug, $params);

    $tokens = new UserTokens($db);

    respond([
        'success'        => true,
        'material_id'    => $result['material_id'],
        'material_slug'  => $result['material_slug'],
        'file_format'    => $result['file_format'],
        'tokens_charged' => $result['tokens_charged'],
        'tokens_left'    => $tokens->getBalance((int)$userId),
        'redirect_url'   => '/material/' . rawurlencode($result['material_slug']) . '/',
        'download_url'   => '/material-download.php?id=' . $result['material_id'],
    ]);
} catch (NotEnoughTokensException $e) {
    respond([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'not_enough_tokens',
        'tokens_left' => (new UserTokens($db))->getBalance((int)$userId),
        'buy_url' => '/material-balance/',
    ], 402);
} catch (OpenRouterAIServiceException $e) {
    error_log('MaterialGenerator AI error: ' . $e->getMessage());
    respond([
        'success' => false,
        'error' => 'Сервис ИИ временно недоступен. Токены возвращены на счёт.',
        'code' => 'ai_error',
        'tokens_left' => (new UserTokens($db))->getBalance((int)$userId),
    ], 502);
} catch (InvalidArgumentException $e) {
    respond([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'invalid_type',
    ], 400);
} catch (Throwable $e) {
    error_log('MaterialGenerator unexpected error: ' . $e->getMessage());
    respond([
        'success' => false,
        'error' => 'Внутренняя ошибка. Если она повторится — напишите в поддержку.',
        'code' => 'internal',
    ], 500);
}

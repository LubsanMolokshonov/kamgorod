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
 * Timeout: 300 секунд — генерация (до 150с) + методическая самопроверка (ещё до 150с).
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
require_once __DIR__ . '/../includes/material-tracking.php';

set_time_limit(300);

function respond(array $data, int $httpCode = 200): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}

// Превью-генерация бесплатна и доступна анониму (без регвола). Оплата — на скачивании.
$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$funnelSessionId = materialFunnelSessionId();
$ip = $_SERVER['REMOTE_ADDR'] ?? null;

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
    'program', 'questions_count', 'slides_count', 'hours', 'test_mode',
    'audience_category_ids', 'audience_type_ids', 'specialization_ids',
];
// test_mode уходит в промпт ИИ — допускаем только известные значения (анти-инъекция).
$allowedTestModes = [
    'один правильный ответ в каждом вопросе',
    'допускаются вопросы с несколькими правильными ответами',
    'смешанный: тест + открытые вопросы',
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
// Жёсткий whitelist для test_mode: чужое значение → дефолт (один правильный ответ).
if (isset($params['test_mode']) && !in_array($params['test_mode'], $allowedTestModes, true)) {
    $params['test_mode'] = $allowedTestModes[0];
}

// Программа обязательна для типов, чей промпт использует {program} (адресность по ФГОС/ФОП).
// На фронте поле required — дублируем на сервере (защита от прямого POST).
// Классный час {program} не использует — для него поле не показывается и не требуется.
$typeForValidation = (new MaterialType($db))->getBySlug($typeSlug);
if ($typeForValidation
    && str_contains((string)($typeForValidation['ai_prompt_template'] ?? ''), '{program}')
    && trim((string)($params['program'] ?? '')) === ''
) {
    respond(['success' => false, 'error' => 'Выберите программу (ФГОС/ФОП)', 'code' => 'program_required'], 400);
}

// Лимит бесплатных превью-генераций (защита от слива денег на ИИ)
$rateError = materialPreviewRateLimit($db, $userId, $funnelSessionId, $ip);
if ($rateError !== null) {
    respond(['success' => false, 'error' => $rateError, 'code' => 'rate_limited'], 429);
}

try {
    $generator = new MaterialGenerator($db);
    $result = $generator->generate($userId, $typeSlug, $params, 'preview', $funnelSessionId, $ip);

    // Залогиненному, не оплатившему скачивание, — запланировать дожим preview_abandon
    if ($userId !== null) {
        try {
            require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
            (new MaterialTokenEmailChain($db))->schedulePreviewAbandon($userId);
        } catch (Throwable $e) {
            error_log('generate schedulePreviewAbandon: ' . $e->getMessage());
        }
    }

    respond([
        'success'           => true,
        'material_id'       => $result['material_id'],
        'material_slug'     => $result['material_slug'],
        'file_format'       => $result['file_format'],
        'unlock_token_cost' => $result['unlock_token_cost'],
        'redirect_url'      => '/material/' . rawurlencode($result['material_slug']) . '/',
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

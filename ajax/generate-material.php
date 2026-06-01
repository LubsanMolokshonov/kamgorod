<?php
/**
 * AJAX: генерация материала через ИИ.
 *
 * POST:
 *   - csrf
 *   - type_slug (slug из material_types)
 *   - params[*] (subject, class, topic, duration, features, questions_count, slides_count, hours, program)
 *
 * Ответ (async): POST ставит задачу в очередь и сразу возвращает её id —
 *   { success: true, generation_id, status_url }
 *   { success: false, error: 'message', code: 'not_enough_tokens'|'invalid_type'|'rate_limited'|'csrf'|'internal' }
 *
 * Саму генерацию (LLM + методическая самопроверка, 60–200с) выполняет фоновый воркер
 * cron/process-material-generations.php. Фронт опрашивает status_url до done/failed.
 * Так запрос не висит и не упирается в таймаут прокси, а сессия не блокируется.
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

// Запрос теперь короткий (только постановка в очередь), но оставляем запас на
// случай медленного списания токенов / БД.
set_time_limit(30);

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

// КРИТИЧНО: дальше сессия только на чтение не нужна — закрываем её, чтобы НЕ держать
// эксклюзивный лок файла сессии. Иначе любой параллельный запрос того же браузера
// (навигация, открытие других страниц) блокировался бы до конца генерации → 504.
// user_id уже прочитан выше, funnelSessionId работает через cookie.
session_write_close();

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
    // Async: только ставим задачу в очередь — ИИ не вызываем в этом запросе.
    $generationId = $generator->enqueue($userId, $typeSlug, $params, 'preview', $funnelSessionId, $ip);

    // Залогиненному, не оплатившему скачивание, — запланировать дожим preview_abandon
    if ($userId !== null) {
        try {
            require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
            (new MaterialTokenEmailChain($db))->schedulePreviewAbandon($userId);
        } catch (Throwable $e) {
            error_log('generate schedulePreviewAbandon: ' . $e->getMessage());
        }
    }

    // Немедленно запускаем фонового воркера (не ждём 1-минутного cron-fallback).
    // Lock-файл внутри воркера не даст дублей, атомарный pending→running — тоже.
    if (function_exists('exec')) {
        $workerPath = realpath(__DIR__ . '/../cron/process-material-generations.php');
        if ($workerPath !== false) {
            $out = [];
            $rc = 0;
            @exec('php ' . escapeshellarg($workerPath) . ' > /dev/null 2>&1 &', $out, $rc);
            // Не критично: при сбое spawn задачу подхватит cron-fallback (раз в минуту).
            // Логируем для диагностики (например, если exec заблокирован на проде).
            if ($rc !== 0) {
                error_log('generate-material: worker spawn failed (rc=' . $rc . '), полагаемся на cron-fallback');
            }
        }
    } else {
        error_log('generate-material: exec() недоступна — генерацию подхватит cron-fallback');
    }

    respond([
        'success'       => true,
        'generation_id' => $generationId,
        'status_url'    => '/ajax/material-generation-status.php?id=' . $generationId,
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
    respond([
        'success' => false,
        'error' => $e->getMessage(),
        'code' => 'invalid_type',
    ], 400);
} catch (Throwable $e) {
    error_log('MaterialGenerator enqueue error: ' . $e->getMessage());
    respond([
        'success' => false,
        'error' => 'Внутренняя ошибка. Если она повторится — напишите в поддержку.',
        'code' => 'internal',
    ], 500);
}

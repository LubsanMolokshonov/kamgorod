<?php
/**
 * Track Visit — создание записи визита
 * POST: session_id, utm_source, utm_medium, utm_campaign, utm_content, utm_term, first_page_url, referrer
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

// Только POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$sessionId = trim($_POST['session_id'] ?? '');
if (empty($sessionId) || strlen($sessionId) > 64) {
    echo json_encode(['success' => false]);
    exit;
}

// Проверка на бота (server-side)
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
$botPatterns = ['googlebot', 'yandexbot', 'bingbot', 'slurp', 'duckduckbot',
    'baiduspider', 'sogou', 'facebookexternalhit', 'twitterbot', 'semrushbot',
    'ahrefsbot', 'mj12bot', 'dotbot', 'petalbot', 'bytespider', 'headlesschrome', 'phantomjs'];
$isBot = 0;
$uaLower = strtolower($userAgent);
foreach ($botPatterns as $pattern) {
    if (strpos($uaLower, $pattern) !== false) {
        $isBot = 1;
        break;
    }
}

// Не записываем ботов
if ($isBot) {
    echo json_encode(['success' => true, 'visit_id' => 0]);
    exit;
}

try {
    $dbObj = new Database($db);

    // Проверяем существующий визит с этим session_id за последние 30 минут
    $existing = $dbObj->queryOne(
        "SELECT id, ab_variant FROM visits WHERE session_id = ? AND started_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 1",
        [$sessionId]
    );

    if ($existing) {
        echo json_encode([
            'success' => true,
            'visit_id' => (int)$existing['id'],
            'ab_variant' => $existing['ab_variant'],
        ]);
        exit;
    }

    // Sanitize inputs
    $utmSource = mb_substr(trim($_POST['utm_source'] ?? ''), 0, 255) ?: null;
    $utmMedium = mb_substr(trim($_POST['utm_medium'] ?? ''), 0, 255) ?: null;
    $utmCampaign = mb_substr(trim($_POST['utm_campaign'] ?? ''), 0, 255) ?: null;
    $utmContent = mb_substr(trim($_POST['utm_content'] ?? ''), 0, 255) ?: null;
    $utmTerm = mb_substr(trim($_POST['utm_term'] ?? ''), 0, 255) ?: null;
    $firstPageUrl = mb_substr(trim($_POST['first_page_url'] ?? ''), 0, 2048) ?: null;
    $referrer = mb_substr(trim($_POST['referrer'] ?? ''), 0, 2048) ?: null;
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;

    // A/B сплит рекомендаций корзины: 50/50 детерминированно по session_id,
    // чтобы в рамках одной сессии пользователь всегда попадал в одну и ту же ветку
    $abVariant = (crc32($sessionId) % 2 === 0) ? 'A' : 'B';

    $visitId = $dbObj->insert('visits', [
        'session_id' => $sessionId,
        'utm_source' => $utmSource,
        'utm_medium' => $utmMedium,
        'utm_campaign' => $utmCampaign,
        'utm_content' => $utmContent,
        'utm_term' => $utmTerm,
        'first_page_url' => $firstPageUrl,
        'referrer' => $referrer,
        'user_agent' => mb_substr($userAgent, 0, 512),
        'ip_address' => $ipAddress,
        'is_bot' => $isBot,
        'ab_variant' => $abVariant,
    ]);

    echo json_encode(['success' => true, 'visit_id' => (int)$visitId, 'ab_variant' => $abVariant]);

} catch (Exception $e) {
    error_log('Track visit error: ' . $e->getMessage());
    echo json_encode(['success' => false]);
}

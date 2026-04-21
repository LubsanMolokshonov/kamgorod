<?php
/**
 * Генерация статьи через Yandex GPT
 */

session_start();
set_time_limit(120);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ArticleGenerator.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

$sessionToken = $_POST['session_token'] ?? '';
if (empty($sessionToken)) {
    echo json_encode(['success' => false, 'message' => 'Сессия не найдена']);
    exit;
}

try {
    $generator = new ArticleGenerator($db);

    // Проверить лимит генераций (макс 5 на сессию)
    $session = $generator->getSession($sessionToken);
    if (!$session) {
        echo json_encode(['success' => false, 'message' => 'Сессия не найдена или завершена']);
        exit;
    }
    if (($session['generation_count'] ?? 0) >= 5) {
        echo json_encode(['success' => false, 'message' => 'Достигнут лимит генераций. Отредактируйте текущую статью.']);
        exit;
    }

    $article = $generator->generateArticle($sessionToken);

    echo json_encode([
        'success' => true,
        'title' => $article['title'],
        'sections' => $article['sections'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Generate article error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка генерации. Попробуйте ещё раз.']);
}

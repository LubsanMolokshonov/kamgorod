<?php
/**
 * Публикация сгенерированной статьи в журнал
 */

session_start();
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
    $result = $generator->publish($sessionToken);

    echo json_encode([
        'success' => true,
        'publication_id' => $result['publication_id'],
        'publication_url' => $result['publication_url'],
        'certificate_url' => $result['certificate_url'],
        'message' => 'Статья успешно опубликована!',
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Publish generated article error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка публикации: ' . $e->getMessage()]);
}

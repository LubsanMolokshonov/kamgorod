<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BlogComment.php';
require_once __DIR__ . '/../includes/blog-discussion.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') { http_response_code(405); throw new InvalidArgumentException('Используйте GET'); }
    $comments = new BlogComment($db);
    $id = filter_input(INPUT_GET, 'publication_id', FILTER_VALIDATE_INT) ?: 0;
    if (!$comments->article($id)) { http_response_code(404); throw new InvalidArgumentException('Статья недоступна'); }
    $page = $comments->threads($id, max(0, filter_input(INPUT_GET, 'before', FILTER_VALIDATE_INT) ?: 0));
    echo json_encode(['success' => true, 'html' => blogDiscussionHtml($page['rows']), 'next' => $page['next'], 'stats' => $comments->stats($id)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(503);
    error_log('Blog comments get: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Не удалось загрузить обсуждение.'], JSON_UNESCAPED_UNICODE);
}

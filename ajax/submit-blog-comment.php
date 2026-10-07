<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/blog-discussion.php';
require_once __DIR__ . '/../classes/BlogComment.php';
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); throw new InvalidArgumentException('Используйте POST'); }
    foreach ($_POST as $value) if (!is_string($value)) throw new InvalidArgumentException('Некорректные данные');
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) { http_response_code(403); throw new InvalidArgumentException('Обновите страницу: срок действия формы истёк.'); }
    if (!empty($_POST['website'])) { echo json_encode(['success' => true, 'status' => 'pending', 'message' => 'Спасибо! Сообщение отправлено на модерацию.']); exit; }
    $userId = getUserId();
    if ($userId) {
        $u = (new Database($db))->queryOne('SELECT full_name, profession FROM users WHERE id = ?', [(int)$userId]);
        if (!empty($u['full_name'])) $_POST['author_name'] = $u['full_name'];
        if (empty($_POST['author_role']) && !empty($u['profession'])) $_POST['author_role'] = $u['profession'];
    }
    $token = blogVoteToken();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    session_write_close();
    $comments = new BlogComment($db);
    $result = $comments->submit($_POST, $token, $userId ? (int)$userId : null, $ip,
        static fn() => blogConsumeLimit('ip:' . $ip, 30) && blogConsumeLimit('browser:' . $token, 10));
    $root = (new Database($db))->queryOne('SELECT root_id FROM blog_comments WHERE id = ?', [$result['id']]);
    $result['root_id'] = (int)$root['root_id'];
    $visible = $result['status'] === 'approved' && $comments->visible($result['id'], (int)$_POST['publication_id']);
    echo json_encode(['success' => true] + $result + ['visible' => $visible, 'message' => $visible ? 'Спасибо! Сообщение опубликовано.' : ($result['status'] === 'rejected' ? 'Сообщение отклонено модератором.' : 'Спасибо! Сообщение сохранено и появится после проверки.')], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    if (http_response_code() < 400) http_response_code(422);
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (OverflowException $e) {
    http_response_code(429); header('Retry-After: 600');
    echo json_encode(['success' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Blog comment submit: ' . $e->getMessage()); http_response_code(503);
    echo json_encode(['success' => false, 'message' => 'Не удалось завершить отправку. Повторите попытку.'], JSON_UNESCAPED_UNICODE);
}

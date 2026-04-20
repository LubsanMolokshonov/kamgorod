<?php
/**
 * Загрузка истории сессии чата (последние сообщения).
 * GET /ai-chat/api/session.php?token=...
 */

require_once __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    ai_json(['success' => false, 'error' => 'method_not_allowed'], 405);
}

$token = trim((string)($_GET['token'] ?? ''));
if ($token === '' || mb_strlen($token) < 16) {
    ai_json(['success' => false, 'error' => 'invalid_token'], 400);
}

try {
    $pdo = ai_get_pdo();
    $stmt = $pdo->prepare('SELECT id FROM ai_chat_sessions WHERE session_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        ai_json(['success' => true, 'messages' => []]);
    }

    $sessionId = (int)$row['id'];
    $stmt = $pdo->prepare('SELECT role, content, metadata, created_at FROM ai_chat_messages WHERE session_id = ? AND role IN (\'user\',\'assistant\') ORDER BY id ASC LIMIT 50');
    $stmt->execute([$sessionId]);
    $messages = [];
    foreach ($stmt->fetchAll() as $m) {
        $metadata = $m['metadata'] ? json_decode($m['metadata'], true) : null;
        $messages[] = [
            'role' => $m['role'],
            'content' => $m['content'],
            'recommendations' => $metadata['recommendations'] ?? [],
            'created_at' => $m['created_at'],
        ];
    }

    ai_json(['success' => true, 'messages' => $messages]);
} catch (Throwable $e) {
    ai_log('SESSION', 'Load failed', ['error' => $e->getMessage()]);
    ai_json(['success' => false, 'error' => 'internal_error'], 500);
}

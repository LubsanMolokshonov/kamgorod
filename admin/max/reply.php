<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Админ: ручной ответ поддержки пользователю в мессенджере «Макс» (через ChatPush).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/ChatpushClient.php';
require_once __DIR__ . '/../../includes/session.php';

Admin::verifySession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/max/');
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('CSRF validation failed');
}

$phone = ChatpushClient::normalizePhone((string)($_POST['phone'] ?? ''));
$message = trim((string)($_POST['message'] ?? ''));

if ($phone === null || $message === '' || mb_strlen($message) > 4096) {
    header('Location: /admin/max/');
    exit;
}

$redirect = '/admin/max/view.php?phone=' . urlencode($phone);

if (!CHATPUSH_ACTIVE || CHATPUSH_API_TOKEN === '') {
    // Отправка выключена — фиксируем неудачу в ленте, чтобы было видно.
    logMaxReply($db, $phone, $message, 'failed', null, null, 'CHATPUSH disabled or no token');
    header('Location: ' . $redirect);
    exit;
}

// user_id для ленты — по последнему сообщению этого диалога.
$uStmt = $db->prepare('SELECT MAX(user_id) FROM max_messages WHERE phone = ?');
$uStmt->execute([$phone]);
$userId = $uStmt->fetchColumn();
$userId = $userId ? (int)$userId : null;

try {
    $client = new ChatpushClient();
    $result = $client->send($phone, $message);
    $status = $result['success'] ? 'sent' : 'failed';
    logMaxReply(
        $db, $phone, $message, $status,
        $result['http_code'] ?? null, $result['response'] ?? null, $result['error'] ?? null, $userId,
        $result['provider_message_id'] ?? null
    );
} catch (\Throwable $e) {
    error_log('[admin/max/reply] ' . $e->getMessage());
    logMaxReply($db, $phone, $message, 'failed', null, null, $e->getMessage(), $userId, null);
}

header('Location: ' . $redirect);
exit;

/**
 * Записать исходящий ответ поддержки в ленту max_messages.
 */
function logMaxReply(
    PDO $pdo, string $phone, string $message, string $status,
    ?int $httpCode, ?string $response, ?string $error, ?int $userId = null, ?string $providerMessageId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO max_messages
             (phone, user_id, direction, author, `text`, `status`, http_code, provider_response, error, sent_by_admin_id, provider_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $phone,
            $userId,
            'out',
            'admin',
            $message,
            $status,
            $httpCode,
            $response !== null ? mb_substr($response, 0, 2000) : null,
            $error,
            (int)($_SESSION['admin_id'] ?? 0) ?: null,
            $providerMessageId,
        ]);
    } catch (\Throwable $e) {
        error_log('[admin/max/reply] logMaxReply failed: ' . $e->getMessage());
    }
}

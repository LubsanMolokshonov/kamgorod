<?php
/**
 * Appeal Rejected Publication AJAX Handler
 * Moves an auto-rejected publication back to 'pending' for manual review
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться']);
    exit;
}

$publicationId = intval($_POST['publication_id'] ?? 0);
if ($publicationId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Некорректный ID публикации']);
    exit;
}

try {
    $database = new Database($db);
    $publicationObj = new Publication($db);
    $publication = $publicationObj->getById($publicationId);

    // Verify ownership
    if (!$publication || (int)$publication['user_id'] !== (int)$_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Публикация не найдена']);
        exit;
    }

    // Only rejected publications can be appealed
    if ($publication['status'] !== 'rejected') {
        echo json_encode(['success' => false, 'message' => 'Обжаловать можно только отклонённые публикации']);
        exit;
    }

    // Prevent double appeals
    if (isset($publication['moderation_type']) && $publication['moderation_type'] === 'appealed') {
        echo json_encode(['success' => false, 'message' => 'Вы уже подавали апелляцию на эту публикацию']);
        exit;
    }

    // Move back to pending for manual review
    $previousReason = $publication['moderation_comment'] ?? '';
    $publicationObj->update($publicationId, [
        'status' => 'pending',
        'moderation_comment' => 'Апелляция автора. Предыдущая причина: ' . $previousReason,
        'moderation_type' => 'appealed',
    ]);

    // Log the appeal
    $database->insert('moderation_log', [
        'publication_id' => $publicationId,
        'action' => 'appealed',
        'reason' => 'Автор подал апелляцию. Предыдущая причина: ' . $previousReason,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Апелляция подана. Ваша публикация будет рассмотрена модератором в течение 1-2 рабочих дней.',
    ]);

} catch (Exception $e) {
    error_log("Appeal publication error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка при подаче апелляции']);
}

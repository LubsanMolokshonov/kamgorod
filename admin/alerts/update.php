<?php
/**
 * Админ: смена статуса / добавление заметки к алерту
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../includes/session.php';

Admin::verifySession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /admin/alerts/');
    exit;
}

if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    die('CSRF validation failed');
}

$id = (int)($_POST['id'] ?? 0);
$status = $_POST['status'] ?? '';
$notes = trim((string)($_POST['admin_notes'] ?? ''));

$validStatuses = ['new', 'in_progress', 'resolved', 'closed'];
if ($id <= 0 || !in_array($status, $validStatuses, true)) {
    header('Location: /admin/alerts/');
    exit;
}

$resolvedAt = in_array($status, ['resolved', 'closed'], true) ? date('Y-m-d H:i:s') : null;

$stmt = $db->prepare(
    'UPDATE support_alerts
     SET status = ?, admin_notes = ?, assigned_to = ?, resolved_at = COALESCE(resolved_at, ?)
     WHERE id = ?'
);
$stmt->execute([
    $status,
    $notes !== '' ? $notes : null,
    (int)($_SESSION['admin_id'] ?? 0) ?: null,
    $resolvedAt,
    $id,
]);

header('Location: /admin/alerts/view.php?id=' . $id);
exit;

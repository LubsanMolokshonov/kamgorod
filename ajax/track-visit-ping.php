<?php
/**
 * Track Visit Ping — обновление длительности визита
 * POST: visit_id, session_id
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$visitId = (int)($_POST['visit_id'] ?? 0);
$sessionId = trim($_POST['session_id'] ?? '');

if ($visitId <= 0 || empty($sessionId)) {
    echo json_encode(['success' => false]);
    exit;
}

try {
    $dbObj = new Database($db);
    $dbObj->execute(
        "UPDATE visits SET last_activity_at = NOW(), duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()) WHERE id = ? AND session_id = ?",
        [$visitId, $sessionId]
    );
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false]);
}

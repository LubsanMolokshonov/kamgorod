<?php
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/Database.php';
require_once __DIR__ . '/../../../classes/Admin.php';
require_once __DIR__ . '/../../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../../includes/session.php';

$current = Admin::verifySession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$sourceId = (int)($_POST['campaign_id'] ?? 0);
$segment  = $_POST['segment'] ?? '';
$newName  = trim($_POST['new_name'] ?? '');

if (!in_array($segment, ['winners','rest_of_base'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'bad segment']);
    exit;
}

try {
    $camp = new OldBaseCampaign($db);
    $newId = $camp->cloneToSegment($sourceId, $segment, [
        'name'       => $newName ?: null,
        'created_by' => $current['id'],
    ]);
    echo json_encode(['success' => true, 'new_id' => $newId]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

<?php
/**
 * AJAX: посчитать число получателей под audience_filter (без записи).
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/Database.php';
require_once __DIR__ . '/../../../classes/Admin.php';
require_once __DIR__ . '/../../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../../includes/session.php';

Admin::verifySession();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST only']);
    exit;
}
if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'CSRF']);
    exit;
}

$type = $_POST['audience_type'] ?? 'all';
$filter = ['type' => $type];

if ($type === 'specific_emails') {
    $raw = trim($_POST['audience_emails'] ?? '');
    $filter['emails'] = array_values(array_filter(
        array_map('trim', preg_split('/[\s,;]+/', $raw)),
        'strlen'
    ));
} elseif (in_array($type, ['opened_in','clicked_in','converted_in','exclude_recipients_of'], true)) {
    $filter['campaign_ids'] = array_map('intval', $_POST['audience_campaign_ids'] ?? []);
    if ($type === 'exclude_recipients_of') {
        $filter['base'] = $_POST['audience_base'] ?? 'all';
    }
}

$excludeCampaignId = (int)($_POST['exclude_campaign_id'] ?? 0);

try {
    $camp = new OldBaseCampaign($db);
    $count = $camp->previewAudience($filter);
    $overlap = $camp->audienceOverlap($filter, $excludeCampaignId);
    echo json_encode(['success' => true, 'count' => $count, 'overlap' => $overlap]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

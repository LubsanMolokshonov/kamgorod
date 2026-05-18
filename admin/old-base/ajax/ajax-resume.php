<?php
/**
 * AJAX: запустить / возобновить кампанию. При первом запуске также планирует получателей.
 */
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/Database.php';
require_once __DIR__ . '/../../../classes/Admin.php';
require_once __DIR__ . '/../../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../../includes/session.php';

Admin::verifySession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$id = (int)($_POST['campaign_id'] ?? 0);

try {
    $camp = new OldBaseCampaign($db);
    $c = $camp->find($id);
    if (!$c) throw new \RuntimeException('Кампания не найдена');

    // Если получателей ещё не планировали — спланировать.
    if ((int)$c['recipient_count'] === 0) {
        $planned = $camp->planRecipients($id);
        if ($planned === 0) {
            throw new \RuntimeException('Аудитория пустая — нечего отправлять');
        }
    }
    $camp->launch($id);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

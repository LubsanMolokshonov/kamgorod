<?php
require_once __DIR__ . '/../includes/session.php';
initSession();
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
require_once __DIR__ . '/../config/database.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($_POST['csrf_token'] ?? null) || !validateCSRFToken($_POST['csrf_token'])) {
    http_response_code(403); echo json_encode(['success'=>false]); exit;
}
$userId = (int)getUserId();
$order = $_POST['order_number'] ?? '';
if (!$userId || !is_string($order) || !preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $order)) {
    http_response_code(400); echo json_encode(['success'=>false]); exit;
}
$token = bin2hex(random_bytes(24));
$q = $db->prepare("UPDATE orders SET metrika_claim_token=?, metrika_claimed_at=UTC_TIMESTAMP()
    WHERE order_number=? AND user_id=? AND payment_status='succeeded' AND metrika_sent_at IS NULL
    AND (metrika_claimed_at IS NULL OR metrika_claimed_at < UTC_TIMESTAMP() - INTERVAL 2 MINUTE)");
$q->execute([$token, $order, $userId]);
echo json_encode(['success'=>$q->rowCount() === 1, 'token'=>$q->rowCount() === 1 ? $token : null]);

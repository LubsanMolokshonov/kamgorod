<?php
/**
 * Mark Metrika Sent — фиксирует, что событие e-commerce purchase для заказа
 * успешно отправлено в Я.Метрику. Без этого ecommerce-replay.js при следующей
 * загрузке снова попробует отправить тот же purchase → дубль в e-commerce-отчёте.
 *
 * Authz: только владелец заказа (session user_id) может пометить свой заказ.
 * Идемпотентно — повторный вызов не меняет уже выставленный timestamp.
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => false]);
    exit;
}

$orderNumber = trim((string)($_POST['order_number'] ?? ''));
if ($orderNumber === '' || !preg_match('~^[A-Za-z0-9_\-]{1,64}$~', $orderNumber)) {
    echo json_encode(['success' => false]);
    exit;
}

$db = new Database($GLOBALS['db']);
$updated = $db->execute(
    "UPDATE orders
        SET metrika_sent_at = NOW()
      WHERE order_number = ?
        AND user_id = ?
        AND payment_status = 'succeeded'
        AND metrika_sent_at IS NULL",
    [$orderNumber, $userId]
);

echo json_encode(['success' => true, 'updated' => (int)$updated]);

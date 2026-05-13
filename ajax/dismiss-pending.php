<?php
/**
 * Мягкое скрытие pending-позиции из блока «Незавершённые покупки».
 * Сама pending-запись в исходной таблице не трогается — просто добавляется
 * запись в dismissed_pending_items (миграция 107).
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться']);
    exit;
}

$type = $_POST['type'] ?? '';
$itemId = (int)($_POST['id'] ?? 0);

$typeMap = [
    'webinar'     => ['webinar_certificates',     'webinar_certificate'],
    'publication' => ['publication_certificates', 'publication_certificate'],
    'olympiad'    => ['olympiad_registrations',   'olympiad_registration'],
];

if ($itemId <= 0 || !isset($typeMap[$type])) {
    echo json_encode(['success' => false, 'message' => 'Некорректные параметры']);
    exit;
}

[$table, $dbType] = $typeMap[$type];

try {
    // Проверка владения pending-записью
    $stmt = $db->prepare("SELECT user_id FROM {$table} WHERE id = ?");
    $stmt->execute([$itemId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row || (int)$row['user_id'] !== $userId) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    $stmt = $db->prepare(
        "INSERT IGNORE INTO dismissed_pending_items (user_id, item_type, item_id)
         VALUES (?, ?, ?)"
    );
    $stmt->execute([$userId, $dbType, $itemId]);

    echo json_encode(['success' => true]);

} catch (Throwable $e) {
    error_log("dismiss-pending error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка']);
}

<?php
/**
 * Track Publication Share — AJAX Handler
 *
 * Фиксирует клик по кнопке «поделиться» публикацией (для аналитики эффекта
 * механики мотивации, см. includes/share-publication.php). Это только метрика:
 * не выдаёт наград, не блокирует. Пишет строку в publication_shares.
 *
 * Паттерн — как ajax/rate-publication.php (POST + CSRF + prepared statement).
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// CSRF
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

$publicationId = (int)($_POST['publication_id'] ?? 0);
$network = $_POST['network'] ?? '';

$allowedNetworks = ['vk', 'telegram', 'whatsapp', 'ok', 'copy', 'native'];

if ($publicationId <= 0 || !in_array($network, $allowedNetworks, true)) {
    echo json_encode(['success' => false, 'message' => 'Некорректные данные']);
    exit;
}

try {
    $stmt = $db->prepare(
        "INSERT INTO publication_shares (publication_id, network, user_id, ip, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $publicationId,
        $network,
        $_SESSION['user_id'] ?? null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);

    echo json_encode(['success' => true]);
} catch (Exception $e) {
    error_log('Track publication share error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка']);
}

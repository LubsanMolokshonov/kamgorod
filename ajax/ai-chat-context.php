<?php
/**
 * Контекст пользователя для виджета ИИ-консультанта.
 * Возвращает данные текущей сессии и состав корзины, которые виджет
 * проксирует в ai-consultant контейнер (он не имеет доступа к PHP-сессии).
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

try {
    $userId = $_SESSION['user_id'] ?? null;
    $user = null;

    if ($userId) {
        $stmt = $db->prepare('SELECT id, email, full_name, phone FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // Состав корзины в универсальном формате {type, id}
    $cart = [];

    foreach (getCart() as $regId) {
        $stmt = $db->prepare('SELECT competition_id FROM registrations WHERE id = ? LIMIT 1');
        $stmt->execute([$regId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['competition_id']) {
            $cart[] = ['type' => 'competition', 'id' => (int)$row['competition_id']];
        }
    }

    foreach (getCartOlympiadRegistrations() as $olympRegId) {
        $stmt = $db->prepare('SELECT olympiad_id FROM olympiad_registrations WHERE id = ? LIMIT 1');
        $stmt->execute([$olympRegId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['olympiad_id']) {
            $cart[] = ['type' => 'olympiad', 'id' => (int)$row['olympiad_id']];
        }
    }

    foreach (getCartWebinarCertificates() as $webCertId) {
        $stmt = $db->prepare('SELECT webinar_id FROM webinar_certificates WHERE id = ? LIMIT 1');
        $stmt->execute([$webCertId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && $row['webinar_id']) {
            $cart[] = ['type' => 'webinar', 'id' => (int)$row['webinar_id']];
        }
    }

    echo json_encode([
        'success' => true,
        'user' => $user ? [
            'id' => (int)$user['id'],
            'email' => $user['email'],
            'full_name' => $user['full_name'] ?? '',
            'phone' => $user['phone'] ?? '',
        ] : null,
        'cart' => $cart,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('ai-chat-context error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'user' => null, 'cart' => []], JSON_UNESCAPED_UNICODE);
}

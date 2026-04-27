<?php
/**
 * AJAX: отправить ответ пользователю ВКонтакте из страницы алерта в админке.
 * Требует авторизации в сессии как администратор.
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Метод не поддерживается');
    }

    // Только для авторизованных администраторов
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Неверный CSRF-токен']);
        exit;
    }

    $alertId = (int)($_POST['alert_id'] ?? 0);
    $message = trim((string)($_POST['message'] ?? ''));

    if ($alertId <= 0) {
        throw new Exception('Не указан alert_id');
    }
    if ($message === '') {
        throw new Exception('Сообщение не может быть пустым');
    }
    if (mb_strlen($message) > 4096) {
        throw new Exception('Сообщение слишком длинное (максимум 4096 символов)');
    }

    // Получаем алерт и проверяем, что источник — ВКонтакте
    $dbWrapper = new Database($db);
    $alert = $dbWrapper->queryOne(
        'SELECT id, source, vk_peer_id FROM support_alerts WHERE id = ? LIMIT 1',
        [$alertId]
    );

    if (!$alert) {
        throw new Exception('Алерт не найден');
    }
    if ($alert['source'] !== 'vk') {
        throw new Exception('Алерт не из ВКонтакте');
    }
    if (empty($alert['vk_peer_id'])) {
        throw new Exception('Нет vk_peer_id — нельзя отправить ответ');
    }

    $peerId = (int)$alert['vk_peer_id'];

    // Отправляем сообщение через VK API
    $token = defined('VK_COMMUNITY_TOKEN') ? VK_COMMUNITY_TOKEN : '';
    if ($token === '') {
        throw new Exception('VK_COMMUNITY_TOKEN не настроен');
    }

    $randomId = random_int(PHP_INT_MIN, PHP_INT_MAX);
    $payload  = http_build_query([
        'peer_id'      => $peerId,
        'message'      => $message,
        'random_id'    => $randomId,
        'access_token' => $token,
        'v'            => '5.131',
    ]);

    $ch = curl_init('https://api.vk.com/method/messages.send');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr !== '') {
        throw new Exception('cURL ошибка: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        throw new Exception('VK API вернул HTTP ' . $httpCode);
    }

    $vkResp = json_decode((string)$resp, true);
    if (!empty($vkResp['error'])) {
        $errMsg = $vkResp['error']['error_msg'] ?? 'неизвестная ошибка';
        $errCode = $vkResp['error']['error_code'] ?? 0;
        throw new Exception("VK API error [{$errCode}]: {$errMsg}");
    }

    // Записываем исходящее сообщение в alert_messages
    $dbWrapper->execute(
        "INSERT INTO alert_messages
         (alert_id, direction, from_email, from_name, to_email, subject, body_text, message_id)
         VALUES (?, 'outbound', ?, ?, ?, NULL, ?, ?)",
        [
            $alertId,
            'info@fgos.pro',
            'Поддержка fgos.pro',
            'vk_peer_' . $peerId . '@vk.fgos.pro',
            $message,
            'vk_out_' . ($vkResp['response'] ?? uniqid('', true)),
        ]
    );

    // Переводим алерт в работу, если он был новым
    $dbWrapper->execute(
        "UPDATE support_alerts SET status = 'in_progress' WHERE id = ? AND status = 'new'",
        [$alertId]
    );

    echo json_encode(['success' => true, 'message' => 'Сообщение отправлено']);

} catch (Throwable $e) {
    error_log('[alert_reply_vk] ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

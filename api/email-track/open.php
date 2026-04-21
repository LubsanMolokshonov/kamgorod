<?php
/**
 * Email open-tracking pixel.
 * Возвращает прозрачный 1×1 GIF, попутно отмечая открытие письма в email_events.
 * Не требует сессий и не плодит куки — чистый endpoint.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$mid = isset($_GET['mid']) ? trim((string)$_GET['mid']) : '';

// Всегда отдаём пиксель (даже при ошибке) — молча, без логов на 404
if (preg_match('~^[a-f0-9]{32}$~', $mid)) {
    try {
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        $isProxy = preg_match('~GoogleImageProxy|YandexImages|MailRu-Prefetcher|bot~i', $ua) === 1;

        if ($isProxy) {
            // Прокси-префетчер — увеличиваем счётчик, но не ставим first-open дату
            $stmt = $db->prepare(
                "UPDATE email_events
                    SET opens_count = opens_count + 1,
                        last_opened_at = NOW()
                  WHERE message_id = ?"
            );
            $stmt->execute([$mid]);
        } else {
            $stmt = $db->prepare(
                "UPDATE email_events
                    SET opens_count = opens_count + 1,
                        opened_at = IFNULL(opened_at, NOW()),
                        last_opened_at = NOW()
                  WHERE message_id = ?"
            );
            $stmt->execute([$mid]);
        }
    } catch (\Throwable $e) {
        error_log('email-track/open: ' . $e->getMessage());
    }
}

header('Content-Type: image/gif');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('Content-Length: 43');
echo base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw==');
exit;

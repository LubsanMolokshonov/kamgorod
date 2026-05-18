<?php
/**
 * Webhook Unisender Go — события доставки писем.
 *
 * Регистрируется в кабинете Unisender Go (Настройки → Webhooks) с URL этого файла
 * и событиями: delivered, soft_bounced, hard_bounced, spam, unsubscribed.
 * Формат — POST JSON:
 *   { "auth": "<secret>", "events_by_user": [ { "events": [
 *       { "event_name": "transactional_email_status",
 *         "event_data": { "email": "...", "status": "delivered|hard_bounced|...",
 *                         "delivery_info": { "destination_response": "..." } } } ] } ] }
 *
 * Обрабатываются только адреса, присутствующие в old_base_subscribers — это
 * целевая база рассылок по старой базе. Для прочих событий webhook молча
 * отвечает 200 (Unisender иначе будет ретраить).
 *
 * Безопасность: если задана константа UNISENDER_WEBHOOK_SECRET — поле "auth"
 * в теле обязано совпадать с ней.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/OldBaseSubscriber.php';
require_once __DIR__ . '/../../classes/OldBaseCampaign.php';

function ub_log(string $msg): void {
    $logFile = __DIR__ . '/../../logs/unisender-webhook.log';
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, $logFile);
}

header('Content-Type: application/json; charset=utf-8');

// Проверка/настроечный GET — Unisender может дёргать URL при регистрации.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'ok']);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);

if (!is_array($payload)) {
    http_response_code(200);
    echo json_encode(['status' => 'error', 'message' => 'invalid json']);
    exit;
}

// Проверка секрета (если задан).
if (defined('UNISENDER_WEBHOOK_SECRET') && UNISENDER_WEBHOOK_SECRET !== '') {
    $auth = $payload['auth'] ?? '';
    if (!hash_equals(UNISENDER_WEBHOOK_SECRET, (string)$auth)) {
        http_response_code(403);
        ub_log('REJECTED | bad auth');
        echo json_encode(['status' => 'error', 'message' => 'forbidden']);
        exit;
    }
}

$subscriber = new OldBaseSubscriber($db);
$campaign   = new OldBaseCampaign($db);

$counts = ['delivered' => 0, 'hard_bounced' => 0, 'soft_bounced' => 0, 'spam' => 0, 'unsubscribed' => 0, 'ignored' => 0];

// Достаём список событий из обоих возможных форматов:
//   events_by_user[].events[]  ИЛИ  events[]  ИЛИ  одиночное событие.
$events = [];
if (!empty($payload['events_by_user']) && is_array($payload['events_by_user'])) {
    foreach ($payload['events_by_user'] as $bucket) {
        foreach (($bucket['events'] ?? []) as $ev) {
            $events[] = $ev;
        }
    }
} elseif (!empty($payload['events']) && is_array($payload['events'])) {
    $events = $payload['events'];
} elseif (!empty($payload['event_data'])) {
    $events = [$payload];
}

foreach ($events as $ev) {
    $data = $ev['event_data'] ?? $ev;
    $email = mb_strtolower(trim((string)($data['email'] ?? '')));
    $status = (string)($data['status'] ?? $ev['event_name'] ?? '');
    if ($email === '' || $status === '') {
        $counts['ignored']++;
        continue;
    }

    $reason = (string)($data['delivery_info']['destination_response']
        ?? $data['comment']
        ?? $data['delivery_info']['delivery_status']
        ?? '');

    try {
        switch ($status) {
            case 'delivered':
                $campaign->markRecipientDelivered($email);
                $counts['delivered']++;
                break;

            case 'hard_bounced':
                $subscriber->markBouncedByEmail($email, $reason ?: 'hard bounce', true);
                $campaign->markRecipientBounced($email, $reason ?: 'hard bounce');
                $counts['hard_bounced']++;
                break;

            case 'soft_bounced':
                $subscriber->markBouncedByEmail($email, $reason ?: 'soft bounce', false);
                $counts['soft_bounced']++;
                break;

            case 'spam':
            case 'spam_block':
            case 'complained':
                $subscriber->markComplainedByEmail($email);
                $counts['spam']++;
                break;

            case 'unsubscribed':
                $subscriber->markUnsubscribedByEmail($email);
                $counts['unsubscribed']++;
                break;

            default:
                // opened/clicked/sent — трекаются собственным пикселем/click-rewrite, игнорируем
                $counts['ignored']++;
        }
    } catch (\Throwable $e) {
        ub_log('ERROR | ' . $email . ' | ' . $status . ' | ' . $e->getMessage());
    }
}

ub_log('PROCESSED | ' . json_encode($counts));

echo json_encode(['status' => 'ok', 'processed' => $counts]);

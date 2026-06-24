<?php
declare(strict_types=1);

// ChatpushClient — класс основного сайта (отправка в «Макс»). Автозагрузчик ai-consultant
// его не видит, поэтому подключаем явно по относительному пути к корню проекта.
require_once __DIR__ . '/../../classes/ChatpushClient.php';

/**
 * Обработка входящего сообщения пользователя в мессенджере «Макс» (через ChatPush).
 *
 * Делает две вещи на каждое входящее:
 *  1) РАЗГОВОРНЫЙ ИИ-ОТВЕТ — переиспользует движок ИИ-чата сайта (SessionStore +
 *     ProductSearch + PromptBuilder + YandexGPTClient) и СРАЗУ отправляет ответ обратно
 *     в «Макс». Синтетическая чат-сессия привязана к телефону, поэтому ИИ помнит контекст
 *     диалога между сообщениями.
 *  2) АЛЕРТ «в случае чего» — отдельная классификация (как для VK/email): если ИИ счёл
 *     сообщение обращением в поддержку (is_alert + confidence ≥ порога) — создаётся алерт
 *     через AlertService::createFromMax().
 *
 * Вся лента (входящее пользователя, авто-ответ ИИ) пишется в max_messages для дашборда.
 */
class MaxInboundProcessor
{
    private PDO $pdo;
    private AlertService $alertService;
    private float $confidenceThreshold;

    public function __construct(PDO $pdo, AlertService $alertService, float $confidenceThreshold = 0.6)
    {
        $this->pdo                 = $pdo;
        $this->alertService        = $alertService;
        $this->confidenceThreshold = $confidenceThreshold;
    }

    /**
     * @param array{provider_message_id:string, phone:string, user_id:?int, user_name:?string, text:string, received_at:?string} $event
     * @return array{status:string, reason:?string, reply_sent:bool, alert_id:?int}
     */
    public function process(array $event): array
    {
        $providerMessageId = trim((string)($event['provider_message_id'] ?? ''));
        $phone   = trim((string)($event['phone'] ?? ''));
        $userId  = isset($event['user_id']) && $event['user_id'] ? (int)$event['user_id'] : null;
        $text    = trim((string)($event['text'] ?? ''));

        if ($phone === '') {
            return $this->result('skipped', 'no_phone');
        }

        // Если провайдер не прислал id — UNIQUE по NULL не защищает от дублей при ретраях.
        // Подстраховываемся дедупом по (phone, text) в коротком окне, чтобы не задвоить ИИ-ответ и алерт.
        if ($providerMessageId === '') {
            ai_log('MAX', 'inbound without provider_message_id', ['phone' => $phone]);
            $dup = $this->pdo->prepare(
                "SELECT id FROM max_messages
                 WHERE phone = ? AND direction = 'in' AND `text` <=> ?
                   AND created_at > (NOW() - INTERVAL 30 SECOND) LIMIT 1"
            );
            $dup->execute([$phone, $text]);
            if ($dup->fetch()) {
                return $this->result('skipped', 'duplicate_no_id');
            }
        }

        // 1) Лог входящего (дедуп по provider_message_id через UNIQUE-ключ).
        $inboundId = $this->logMessage([
            'phone'               => $phone,
            'user_id'             => $userId,
            'direction'           => 'in',
            'author'              => 'user',
            'text'                => $text,
            'provider_message_id' => $providerMessageId !== '' ? $providerMessageId : null,
        ]);
        if ($inboundId === 0) {
            // Уже обрабатывали это сообщение.
            return $this->result('skipped', 'duplicate');
        }

        // 2) Фильтр шума — на пустое/слишком короткое не дёргаем ИИ и не отправляем ответ.
        if ($this->detectNoise($text) !== null) {
            return $this->result('skipped', 'noise');
        }

        // 3) Разговорный ИИ-ответ + отправка обратно в «Макс».
        $replySent = $this->generateAndSendReply($phone, $userId, $text);

        // 4) Классификация на алерт (отдельный вызов, per-message — как для VK).
        $alertId = $this->maybeCreateAlert($providerMessageId, $phone, $userId, $event['user_name'] ?? null, $text, $event['received_at'] ?? null, $inboundId);

        return $this->result('ok', null, $replySent, $alertId);
    }

    // -----------------------------------------------------------------------

    /**
     * Сгенерировать ответ ИИ-менеджера и отправить его пользователю в «Макс».
     * Контекст диалога хранится в синтетической чат-сессии, привязанной к телефону.
     */
    private function generateAndSendReply(string $phone, ?int $userId, string $text): bool
    {
        try {
            $sessions = new SessionStore($this->pdo);
            // Токен ≥16 символов — гард совместим с основным ИИ-чатом ("maxconv_" + 79XXXXXXXXX).
            $token   = 'maxconv_' . $phone;
            $session = $sessions->findOrCreate($token, $userId, null, 'max');
            $sid     = (int)$session['id'];

            $sessions->saveMessage($sid, 'user', $text);

            // История без только что добавленного user-сообщения (оно идёт отдельным последним).
            $history = $sessions->getRecentMessages($sid, 10);
            if (!empty($history) && end($history)['role'] === 'user') {
                array_pop($history);
            }

            try {
                $products = (new ProductSearch($this->pdo))->search($text, 6);
            } catch (Throwable $e) {
                $products = [];
            }

            $messages = PromptBuilder::buildChatMessages($history, $text, $products, null, []);
            $response = (new YandexGPTClient(20))->complete($messages, 0.5, 700);

            // Вырезаем служебный маркер создания алерта — алерты создаём отдельным потоком,
            // пользователь маркер видеть не должен.
            $reply = trim(preg_replace('/\[\[CREATE_ALERT\]\][\s\S]*?\[\[\/CREATE_ALERT\]\]/u', '', (string)$response['text']) ?? '');
            if ($reply === '') {
                $reply = 'Спасибо за сообщение! Мы получили его и скоро ответим.';
            }

            $client = new ChatpushClient();
            $send   = $client->send($phone, $reply);

            $sessions->saveMessage($sid, 'assistant', $reply, ['channel' => 'max'], $response['tokens'] ?? null);

            $this->logMessage([
                'phone'               => $phone,
                'user_id'             => $userId,
                'direction'           => 'out',
                'author'              => 'ai',
                'text'                => $reply,
                'status'              => !empty($send['success']) ? 'sent' : 'failed',
                'http_code'           => $send['http_code'] ?? null,
                'provider_response'   => isset($send['response']) ? mb_substr((string)$send['response'], 0, 2000) : null,
                'error'               => $send['error'] ?? null,
                'provider_message_id' => $send['provider_message_id'] ?? null,
            ]);

            return !empty($send['success']);
        } catch (Throwable $e) {
            ai_log('MAX', 'AI reply failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            // Фиксируем неудачную попытку ответа в ленте, чтобы менеджер увидел и ответил вручную.
            $this->logMessage([
                'phone'     => $phone,
                'user_id'   => $userId,
                'direction' => 'out',
                'author'    => 'ai',
                'text'      => null,
                'status'    => 'failed',
                'error'     => 'AI reply exception: ' . mb_substr($e->getMessage(), 0, 500),
            ]);
            return false;
        }
    }

    /**
     * Классифицировать входящее: если это обращение в поддержку — создать алерт.
     * @return int|null alert_id или null
     */
    private function maybeCreateAlert(
        string $providerMessageId,
        string $phone,
        ?int $userId,
        ?string $userName,
        string $text,
        ?string $receivedAt,
        int $inboundId
    ): ?int {
        try {
            $messages = PromptBuilder::buildEmailClassificationMessages('', $text, "max_{$phone}");
            $response = (new YandexGPTClient(10))->complete($messages, 0.2, 200);

            $parsed = null;
            if (preg_match('/\{[\s\S]*\}/', (string)$response['text'], $m)) {
                $parsed = json_decode($m[0], true);
            }
            if (!is_array($parsed)) {
                return null;
            }

            $isAlert    = (bool)($parsed['is_alert'] ?? false);
            $confidence = (float)($parsed['confidence'] ?? 0.0);
            $cat        = $parsed['category'] ?? 'other';
            $summary    = isset($parsed['summary']) ? mb_substr((string)$parsed['summary'], 0, 500) : null;

            if (!$isAlert || $confidence < $this->confidenceThreshold) {
                return null;
            }

            $alertId = $this->alertService->createFromMax([
                'provider_message_id' => $providerMessageId,
                'phone'               => $phone,
                'user_id'             => $userId,
                'from_name'           => $userName,
                'text'                => $text,
                'received_at'         => $receivedAt,
            ], [
                'summary'  => $summary,
                'category' => in_array($cat, ['payment','technical','content','access','other'], true) ? $cat : null,
            ]);

            // Привязываем алерт к строке входящего в ленте.
            if ($alertId > 0 && $inboundId > 0) {
                $upd = $this->pdo->prepare('UPDATE max_messages SET alert_id = ? WHERE id = ?');
                $upd->execute([$alertId, $inboundId]);
            }

            return $alertId > 0 ? $alertId : null;
        } catch (Throwable $e) {
            ai_log('MAX', 'Alert classification failed', ['phone' => $phone, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Вставить строку в ленту max_messages. Возвращает id, либо 0 если это дубль
     * по provider_message_id (INSERT IGNORE → 0 затронутых строк).
     */
    private function logMessage(array $row): int
    {
        try {
            $stmt = $this->pdo->prepare(
                'INSERT IGNORE INTO max_messages
                 (phone, user_id, direction, author, `text`, `status`, http_code, provider_response, error, order_id, alert_id, provider_message_id, sent_by_admin_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $row['phone'],
                $row['user_id']             ?? null,
                $row['direction'],
                $row['author'],
                $row['text']                ?? null,
                $row['status']              ?? null,
                $row['http_code']           ?? null,
                $row['provider_response']   ?? null,
                $row['error']               ?? null,
                $row['order_id']            ?? null,
                $row['alert_id']            ?? null,
                $row['provider_message_id'] ?? null,
                $row['sent_by_admin_id']    ?? null,
            ]);
            return (int)$this->pdo->lastInsertId();
        } catch (Throwable $e) {
            ai_log('MAX', 'logMessage failed', ['error' => $e->getMessage()]);
            return 0;
        }
    }

    private function detectNoise(string $text): ?string
    {
        if ($text === '') {
            return 'empty_text';
        }
        $stripped = trim(preg_replace('/[\x{1F000}-\x{1FFFF}\x{2600}-\x{27BF}]/u', '', $text) ?? $text);
        if (mb_strlen($stripped) < 5) {
            return 'too_short';
        }
        return null;
    }

    private function result(string $status, ?string $reason, bool $replySent = false, ?int $alertId = null): array
    {
        return [
            'status'     => $status,
            'reason'     => $reason,
            'reply_sent' => $replySent,
            'alert_id'   => $alertId,
        ];
    }
}

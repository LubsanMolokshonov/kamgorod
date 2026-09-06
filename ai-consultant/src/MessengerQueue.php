<?php
declare(strict_types=1);

class MessengerQueue
{
    private PDO $pdo;

    public function __construct(PDO $pdo) { $this->pdo = $pdo; }

    /** Регистрирует чат, вычисляет eligibility и дедуплицированно ставит событие в очередь. */
    public function ingest(array $event): array
    {
        $channel = (string)$event['channel'];
        $chatId = (string)$event['chat_id'];
        $chat = $this->discoverChat($channel, $chatId, (string)($event['chat_type'] ?? 'unknown'), $event['chat_title'] ?? null);
        $eligible = MessengerMessagePolicy::shouldAnswer($event, (string)$chat['reply_policy']);
        $status = MESSENGER_AI_ACTIVE && !empty($chat['is_enabled']) && $eligible ? 'pending' : 'skipped';
        $reason = !MESSENGER_AI_ACTIVE ? 'disabled' : (empty($chat['is_enabled']) ? 'chat_not_allowed' : (!$eligible ? 'not_a_question' : null));

        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO ai_messenger_events
             (channel, provider_update_id, provider_message_id, chat_id, user_id, user_name, chat_type,
              message_text, reply_to_message_id, reply_to_bot, raw_payload, status, error, processed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $channel, (string)$event['provider_update_id'], $event['provider_message_id'] ?? null,
            $chatId, $event['user_id'] ?? null, $event['user_name'] ?? null, $event['chat_type'] ?? 'unknown',
            mb_substr((string)($event['text'] ?? ''), 0, MESSENGER_MAX_INPUT_CHARS),
            $event['reply_to_message_id'] ?? null, !empty($event['reply_to_bot']) ? 1 : 0,
            json_encode($event['raw_payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status, $reason, $status === 'skipped' ? date('Y-m-d H:i:s') : null,
        ]);

        return ['inserted' => $stmt->rowCount() === 1, 'status' => $status, 'reason' => $reason];
    }

    private function discoverChat(string $channel, string $chatId, string $chatType, ?string $title): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_messenger_chats (channel, chat_id, title, chat_type, last_active_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE title = COALESCE(VALUES(title), title), chat_type = VALUES(chat_type), last_active_at = NOW()'
        );
        $stmt->execute([$channel, $chatId, $title ? mb_substr($title, 0, 255) : null, $chatType]);
        $stmt = $this->pdo->prepare('SELECT is_enabled, reply_policy FROM ai_messenger_chats WHERE channel = ? AND chat_id = ?');
        $stmt->execute([$channel, $chatId]);
        return $stmt->fetch() ?: ['is_enabled' => 0, 'reply_policy' => 'questions'];
    }

    public function claim(): ?array
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->exec("UPDATE ai_messenger_events SET status='pending', locked_at=NULL WHERE status='processing' AND locked_at < NOW() - INTERVAL 5 MINUTE AND attempts < 3");
            $stmt = $this->pdo->query(
                "SELECT * FROM ai_messenger_events
                 WHERE status='pending' AND available_at <= NOW() AND attempts < 3
                 ORDER BY id ASC LIMIT 1 FOR UPDATE SKIP LOCKED"
            );
            $row = $stmt->fetch();
            if (!$row) { $this->pdo->commit(); return null; }
            $upd = $this->pdo->prepare("UPDATE ai_messenger_events SET status='processing', attempts=attempts+1, locked_at=NOW() WHERE id=?");
            $upd->execute([(int)$row['id']]);
            $this->pdo->commit();
            $row['attempts'] = (int)$row['attempts'] + 1;
            return $row;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function overLimits(array $event): ?string
    {
        if (empty($event['response_text']) && MESSENGER_DAILY_CAP > 0) {
            $stmt = $this->pdo->query("SELECT COUNT(*) FROM ai_messenger_events WHERE status IN ('sent','escalated') AND processed_at >= CURDATE()");
            if ((int)$stmt->fetchColumn() >= MESSENGER_DAILY_CAP) return 'daily_cap';
        }
        if (empty($event['response_text']) && MESSENGER_DAILY_TOKEN_BUDGET > 0) {
            $stmt = $this->pdo->query("SELECT COALESCE(SUM(COALESCE(tokens_in,0)+COALESCE(tokens_out,0)),0) FROM ai_messenger_events WHERE status IN ('sent','escalated') AND processed_at >= CURDATE()");
            if ((int)$stmt->fetchColumn() >= MESSENGER_DAILY_TOKEN_BUDGET) return 'daily_token_budget';
        }
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM ai_messenger_events WHERE channel=? AND chat_id=? AND status IN ('sent','escalated') AND processed_at > NOW() - INTERVAL 1 MINUTE");
        $stmt->execute([$event['channel'], $event['chat_id']]);
        return (int)$stmt->fetchColumn() >= MESSENGER_PER_CHAT_MINUTE ? 'chat_rate_limit' : null;
    }

    public function defer(int $id, string $reason, int $seconds): void
    {
        $stmt = $this->pdo->prepare("UPDATE ai_messenger_events SET status='pending', available_at=DATE_ADD(NOW(), INTERVAL ? SECOND), locked_at=NULL, error=? WHERE id=?");
        $stmt->execute([$seconds, mb_substr($reason, 0, 1000), $id]);
    }

    /** Резервирует для MAX следующий слот отправки: не более 2 сообщений/с на чат. */
    public function reserveMaxSendSlot(string $chatId): int
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                "SELECT GREATEST(0, TIMESTAMPDIFF(MICROSECOND, NOW(6), COALESCE(next_send_at, NOW(6))))
                 FROM ai_messenger_chats WHERE channel='max_bot' AND chat_id=? FOR UPDATE"
            );
            $stmt->execute([$chatId]);
            $delayMicros = (int)$stmt->fetchColumn();
            $stmt = $this->pdo->prepare(
                "UPDATE ai_messenger_chats
                 SET next_send_at=DATE_ADD(GREATEST(COALESCE(next_send_at,NOW(6)),NOW(6)), INTERVAL 500000 MICROSECOND)
                 WHERE channel='max_bot' AND chat_id=?"
            );
            $stmt->execute([$chatId]);
            $this->pdo->commit();
            return $delayMicros;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
    }

    public function savePrepared(int $id, string $text, ?int $alertId, array $meta = []): void
    {
        $stmt = $this->pdo->prepare('UPDATE ai_messenger_events SET response_text=?, alert_id=?, tokens_in=?, tokens_out=?, model=? WHERE id=?');
        $stmt->execute([mb_substr($text, 0, 4000), $alertId, $meta['tokens_in'] ?? null, $meta['tokens_out'] ?? null, $meta['model'] ?? null, $id]);
    }

    public function finish(int $id, string $status, array $meta = []): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ai_messenger_events SET status=?, response_message_id=?, alert_id=?, tokens_in=?, tokens_out=?, model=?, error=?, locked_at=NULL, processed_at=NOW() WHERE id=?'
        );
        $stmt->execute([
            $status, $meta['response_message_id'] ?? null, $meta['alert_id'] ?? null,
            $meta['tokens_in'] ?? null, $meta['tokens_out'] ?? null, $meta['model'] ?? null,
            isset($meta['error']) ? mb_substr((string)$meta['error'], 0, 4000) : null, $id,
        ]);
    }
}

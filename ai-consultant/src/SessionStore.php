<?php
declare(strict_types=1);

/**
 * Хранилище сессий чата и истории сообщений.
 */
class SessionStore
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Найти или создать сессию по токену (генерируется клиентом в localStorage).
     *
     * @return array{id:int, session_token:string, user_id:?int, user_email:?string}
     */
    public function findOrCreate(string $token, ?int $userId, ?string $userEmail, ?string $pageContext): array
    {
        $stmt = $this->pdo->prepare('SELECT id, session_token, user_id, user_email FROM ai_chat_sessions WHERE session_token = ? LIMIT 1');
        $stmt->execute([$token]);
        $row = $stmt->fetch();

        if ($row) {
            // Обновляем user_id/email если появились в текущей сессии
            if (($userId && !$row['user_id']) || ($userEmail && !$row['user_email'])) {
                $upd = $this->pdo->prepare('UPDATE ai_chat_sessions SET user_id = COALESCE(user_id, ?), user_email = COALESCE(user_email, ?) WHERE id = ?');
                $upd->execute([$userId, $userEmail, $row['id']]);
                $row['user_id'] = $row['user_id'] ?: $userId;
                $row['user_email'] = $row['user_email'] ?: $userEmail;
            }
            return [
                'id' => (int)$row['id'],
                'session_token' => $row['session_token'],
                'user_id' => $row['user_id'] ? (int)$row['user_id'] : null,
                'user_email' => $row['user_email'],
            ];
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_chat_sessions (session_token, user_id, user_email, page_context, user_agent, ip_address)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $token,
            $userId,
            $userEmail,
            $pageContext,
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null,
        ]);

        return [
            'id' => (int)$this->pdo->lastInsertId(),
            'session_token' => $token,
            'user_id' => $userId,
            'user_email' => $userEmail,
        ];
    }

    public function saveMessage(int $sessionId, string $role, string $content, ?array $metadata = null, ?int $tokens = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ai_chat_messages (session_id, role, content, metadata, tokens_used)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $sessionId,
            $role,
            $content,
            $metadata ? json_encode($metadata, JSON_UNESCAPED_UNICODE) : null,
            $tokens,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Получить последние N сообщений сессии для контекста.
     * @return array<int, array{role:string, content:string}>
     */
    public function getRecentMessages(int $sessionId, int $limit = 10): array
    {
        $limitSafe = (int)$limit;
        $stmt = $this->pdo->prepare(
            "SELECT role, content FROM ai_chat_messages
             WHERE session_id = ?
             ORDER BY id DESC LIMIT {$limitSafe}"
        );
        $stmt->execute([$sessionId]);
        $rows = array_reverse($stmt->fetchAll());
        return array_map(fn($r) => ['role' => $r['role'], 'content' => $r['content']], $rows);
    }
}

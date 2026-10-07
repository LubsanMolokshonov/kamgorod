<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/Database.php';

/** Обсуждения редакционных статей; оценки не смешиваются с отзывами продуктов. */
class BlogComment {
    private Database $db;
    private $moderator;

    public function __construct(private PDO $pdo, ?callable $moderator = null) {
        $this->db = new Database($pdo);
        $this->moderator = $moderator ?? static function (string $text): array {
            require_once __DIR__ . '/YandexGPTModerator.php';
            return (new YandexGPTModerator())->moderateReview($text, true);
        };
    }

    public function article(int $id): ?array {
        return $this->db->queryOne("SELECT id, slug, title FROM publications WHERE id = ? AND source = 'blog' AND status = 'published' AND (redirect_to_slug IS NULL OR redirect_to_slug = '')", [$id]) ?: null;
    }

    public function visible(int $id, int $articleId): bool {
        $row = $this->db->queryOne("WITH RECURSIVE ancestors AS (
            SELECT id, parent_id, status FROM blog_comments WHERE id = ? AND publication_id = ?
            UNION ALL SELECT c.id, c.parent_id, c.status FROM blog_comments c JOIN ancestors a ON c.id = a.parent_id
        ) SELECT COUNT(*) AS n, SUM(status <> 'approved') AS hidden FROM ancestors", [$id, $articleId]);
        return (int)$row['n'] > 0 && (int)$row['hidden'] === 0;
    }

    public function submit(array $input, string $token, ?int $userId, string $ip, ?callable $consumeLimit = null): array {
        $articleId = (int)($input['publication_id'] ?? 0);
        $parentId = (int)($input['parent_id'] ?? 0);
        $name = trim((string)($input['author_name'] ?? ''));
        $role = trim((string)($input['author_role'] ?? ''));
        $body = trim((string)($input['body'] ?? ''));
        $rawRating = (string)($input['rating'] ?? '');
        $rating = $rawRating === '' ? null : (preg_match('/^[1-5]$/D', $rawRating) ? (int)$rawRating : 0);
        $key = (string)($input['request_key'] ?? '');
        if ($articleId < 1 || $parentId < 0 || $name === '' || mb_strlen($name) > 120 || mb_strlen($role) > 160 || mb_strlen($body) > 2000
            || ($rating !== null && $rating === 0) || ($parentId && ($body === '' || $rating !== null)) || ($body === '' && $rating === null)
            || !preg_match('/^[a-f0-9]{32}$/D', $key) || !preg_match('/^[a-f0-9]{32}$/D', $token)) {
            throw new InvalidArgumentException('Укажите имя и текст или оценку. Ответ должен содержать текст без оценки.');
        }
        if (!$this->article($articleId)) throw new InvalidArgumentException('Статья недоступна.');
        $hash = hash('sha256', json_encode([$parentId, $name, $role, $body, $rating, $userId], JSON_UNESCAPED_UNICODE));
        // Сериализация отправок одного браузера; уникальные индексы остаются последней защитой.
        $lock = 'blog:' . hash('sha1', $articleId . ':' . $token);
        if (!(int)$this->db->queryOne('SELECT GET_LOCK(?, 10) AS acquired', [$lock])['acquired']) throw new RuntimeException('Попробуйте отправить сообщение позже.');
        try {
            $existing = $this->db->queryOne('SELECT id, status, request_hash FROM blog_comments WHERE publication_id = ? AND vote_token = ? AND request_key = ?', [$articleId, $token, $key]);
            if ($existing) {
                if (!hash_equals($existing['request_hash'], $hash)) throw new InvalidArgumentException('Запрос уже использован. Обновите страницу перед новой отправкой.');
                return ['id' => (int)$existing['id'], 'status' => $existing['status'], 'duplicate' => true];
            }
            if ($rating !== null && $this->db->queryOne('SELECT id FROM blog_comments WHERE publication_id = ? AND rating_token = ?', [$articleId, $token])) {
                throw new InvalidArgumentException('Вы уже оценили эту статью. Выберите «Без оценки», чтобы отправить комментарий.');
            }
            $parent = null;
            if ($parentId) {
                if (!$this->visible($parentId, $articleId)) throw new InvalidArgumentException('Сообщение, на которое вы отвечаете, недоступно.');
                $parent = $this->db->queryOne('SELECT root_id FROM blog_comments WHERE id = ?', [$parentId]);
            }
            if ($consumeLimit && !$consumeLimit()) throw new OverflowException('Слишком много сообщений. Повторите попытку через 10 минут.');
            $this->pdo->beginTransaction();
            $id = (int)$this->db->insert('blog_comments', [
                'publication_id' => $articleId, 'parent_id' => $parentId ?: null, 'root_id' => $parent['root_id'] ?? null,
                'user_id' => $userId, 'author_name' => $name, 'author_role' => $role ?: null, 'body' => $body, 'rating' => $rating,
                'vote_token' => $token, 'request_key' => $key, 'request_hash' => $hash, 'ip_address' => $ip ?: null,
            ]);
            if (!$parentId) $this->db->execute('UPDATE blog_comments SET root_id = ? WHERE id = ?', [$id, $id]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        } finally {
            $this->db->queryOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        try {
            // Проверяем также отображаемые имя и роль, чтобы спам не обходил проверку через них.
            $result = ($this->moderator)("Имя: " . $name . "\nРоль: " . $role . "\nКомментарий: " . ($body !== "" ? $body : "Оценка без текста"));
            $status = !empty($result['ok']) ? 'approved' : 'pending';
            $reason = mb_substr((string)($result['reason'] ?? ''), 0, 255);
        } catch (Throwable $e) {
            error_log('Blog moderation: ' . $e->getMessage());
            $status = 'pending'; $reason = 'Автопроверка недоступна';
        }
        // Ручное решение, принятое за время сетевого запроса, имеет приоритет.
        $this->db->execute("UPDATE blog_comments SET status = ?, moderation_reason = ?, moderated_at = NOW() WHERE id = ? AND status = 'pending' AND moderated_at IS NULL", [$status, $reason, $id]);
        $saved = $this->db->queryOne('SELECT status FROM blog_comments WHERE id = ?', [$id]);
        return ['id' => $id, 'status' => $saved['status'], 'duplicate' => false];
    }

    public function stats(int $articleId): array {
        $rating = $this->db->queryOne("SELECT COUNT(rating) AS rating_count, COALESCE(ROUND(AVG(rating), 1), 0) AS rating_avg FROM blog_comments WHERE publication_id = ? AND parent_id IS NULL AND status = 'approved'", [$articleId]);
        $count = $this->db->queryOne("WITH RECURSIVE visible AS (
            SELECT id, body FROM blog_comments WHERE publication_id = ? AND parent_id IS NULL AND status = 'approved'
            UNION ALL SELECT c.id, c.body FROM blog_comments c JOIN visible v ON c.parent_id = v.id WHERE c.status = 'approved'
        ) SELECT COUNT(*) AS n FROM visible WHERE body <> ''", [$articleId]);
        return ['count' => (int)$rating['rating_count'], 'avg' => (float)$rating['rating_avg'], 'comments' => (int)$count['n']];
    }

    /** Курсор по ID корней: новые сообщения не сдвигают уже загруженные страницы. */
    public function threads(int $articleId, int $before = 0): array {
        $roots = $this->db->query("SELECT id FROM blog_comments WHERE publication_id = ? AND parent_id IS NULL AND status = 'approved' AND (? = 0 OR id < ?) ORDER BY id DESC LIMIT 21", [$articleId, $before, $before]);
        $more = count($roots) > 20;
        $ids = array_map('intval', array_column(array_slice($roots, 0, 20), 'id'));
        if (!$ids) return ['rows' => [], 'next' => null];
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $rows = $this->db->query("WITH RECURSIVE visible AS (
            SELECT id FROM blog_comments WHERE id IN ($ph)
            UNION ALL SELECT c.id FROM blog_comments c JOIN visible v ON c.parent_id = v.id WHERE c.status = 'approved'
        ) SELECT c.id, c.parent_id, c.root_id, c.author_name, c.author_role, c.body, c.rating, c.created_at, p.author_name AS parent_name
          FROM visible v JOIN blog_comments c ON c.id = v.id LEFT JOIN blog_comments p ON p.id = c.parent_id
          ORDER BY c.root_id DESC, c.id ASC", $ids);
        return ['rows' => $rows, 'next' => $more ? end($ids) : null];
    }

    public function moderate(int $id, string $status): void {
        if (!in_array($status, ['approved', 'rejected'], true)) throw new InvalidArgumentException('Неизвестный статус');
        $this->db->execute('UPDATE blog_comments SET status = ?, moderation_reason = ?, moderated_at = NOW() WHERE id = ?', [$status, 'Решение администратора', $id]);
    }
}

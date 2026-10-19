<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/site.php';

final class VkBlogPublisher
{
    private const ELIGIBLE = "source = 'blog' AND status = 'published' AND (redirect_to_slug IS NULL OR redirect_to_slug = '')";
    private string $today;

    public function __construct(private PDO $db, private VkBlogGateway $vk, private VkBlogSiteGateway $site,
        private int $groupId, private Closure $notify, ?string $today = null)
    {
        $this->today = $today ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function query(string $sql, array $params = []): PDOStatement
    {
        $q = $this->db->prepare($sql);
        $q->execute($params);
        return $q;
    }

    public function initialize(): void
    {
        if ($this->query('SELECT group_id FROM vk_blog_channels WHERE group_id = ?', [$this->groupId])->fetchColumn()) {
            throw new RuntimeException('Канал уже инициализирован; архив не изменён');
        }
        $this->db->beginTransaction();
        try {
            $this->query('INSERT INTO vk_blog_channels (group_id, initialized_at) VALUES (?, ?)',
                [$this->groupId, gmdate('Y-m-d H:i:s')]);
            $this->enqueue(true);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }

    private function enqueue(bool $archive): void
    {
        $articles = $this->query('SELECT id FROM publications WHERE ' . self::ELIGIBLE .
            ' ORDER BY published_at, id')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($articles as $id) {
            if (!$this->query('SELECT id FROM vk_blog_deliveries WHERE publication_id = ? AND group_id = ?',
                [$id, $this->groupId])->fetchColumn()) {
                $this->query('INSERT INTO vk_blog_deliveries (publication_id, group_id, is_archive, operation_key) VALUES (?, ?, ?, ?)',
                    [$id, $this->groupId, (int)$archive, hash('sha256', 'fgos-blog:' . $this->groupId . ':' . $id)]);
            }
        }
    }

    public function preview(string $baseUrl): array
    {
        // Не требует миграции или подключения к VK; не пишет в БД.
        $rows = $this->query('SELECT * FROM publications WHERE ' . self::ELIGIBLE . ' ORDER BY published_at, id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $a) => ['publication_id' => $a['id']] +
            VkBlogSite::payload($a, rtrim($baseUrl, '/') . '/blog/' . rawurlencode($a['slug']) . '/'), $rows);
    }

    public function run(?int $articleId = null): array
    {
        if (!$this->query('SELECT group_id FROM vk_blog_channels WHERE group_id = ?', [$this->groupId])->fetchColumn()) {
            throw new RuntimeException('Сначала явно зафиксируйте архив: --initialize');
        }
        $this->vk->preflight();
        $this->enqueue(false);
        $stats = ['sent' => 0, 'reconciled' => 0, 'deferred' => 0];
        // Любой незавершённый запрос wall.post после перезапуска считается неопределённым.
        $this->query("UPDATE vk_blog_deliveries SET state = 'unknown' WHERE group_id = ? AND state = 'sending'", [$this->groupId]);
        $unknown = $this->query("SELECT * FROM vk_blog_deliveries WHERE group_id = ? AND state = 'unknown'", [$this->groupId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($unknown as $row) {
            $payload = is_string($row['payload']) ? json_decode($row['payload'], true) : null;
            if (!is_array($payload) || !isset($payload['message'], $payload['url']) || (empty($row['photo_id']) && empty($payload['text_only']))) {
                ($this->notify)('vk_blog_invalid_' . $row['id'], 'Повреждено состояние отправки; повтор заблокирован', ['delivery_id' => $row['id']]);
                $stats['deferred']++;
                continue;
            }
            $post = $this->vk->reconcile($payload, (string)$row['photo_id']);
            if ($post !== null) {
                $this->markSent((int)$row['id'], $post);
                $stats['reconciled']++;
            } else {
                ($this->notify)('vk_blog_unknown_' . $row['id'], 'Результат публикации неизвестен; повтор заблокирован', ['delivery_id' => $row['id']]);
                $stats['deferred']++;
            }
        }
        $rows = $this->query("SELECT d.* FROM vk_blog_deliveries d JOIN publications p ON p.id = d.publication_id
            WHERE d.group_id = ? AND d.state = 'pending' AND p." . self::ELIGIBLE . "
            ORDER BY d.is_archive, p.published_at, p.id", [$this->groupId])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($articleId !== null && (int)$row['publication_id'] !== $articleId) { continue; }
            // После исчерпания дневной квоты не скачиваем остальные обложки архива.
            if ((int)$row['is_archive'] === 1 && !$this->reserveArchive((int)$row['id'], false)) { continue; }
            if ($this->deliver($row)) { $stats['sent']++; } else { $stats['deferred']++; }
        }
        return $stats;
    }

    private function reserveArchive(int $id, bool $reserve = true): bool
    {
        $channel = $this->query('SELECT * FROM vk_blog_channels WHERE group_id = ?', [$this->groupId])->fetch(PDO::FETCH_ASSOC);
        if ($channel['archive_day'] === $this->today) { return (int)$channel['archive_delivery_id'] === $id; }
        if (!$reserve) { return true; }
        $this->query('UPDATE vk_blog_channels SET archive_day = ?, archive_delivery_id = ? WHERE group_id = ?',
            [$this->today, $id, $this->groupId]);
        return true;
    }

    private function article(int $id): array|false
    {
        // Просмотры и другие служебные счётчики меняются при чтении страницы и не являются правкой статьи.
        return $this->query('SELECT id, source, status, title, slug, annotation, content, cover_image_url, redirect_to_slug
            FROM publications WHERE id = ? AND ' . self::ELIGIBLE, [$id])->fetch(PDO::FETCH_ASSOC);
    }

    private function markSent(int $id, int $post): void
    {
        $this->query("UPDATE vk_blog_deliveries SET state = 'sent', post_id = ?, sent_at = ?, last_error = NULL WHERE id = ?",
            [$post, gmdate('Y-m-d H:i:s'), $id]);
    }

    private function deliver(array $row): bool
    {
        $id = (int)$row['id'];
        $started = false;
        try {
            $article = $this->article((int)$row['publication_id']);
            if (!$article) { return false; }
            $prepared = $this->site->prepare($article);
            // Повторная проверка после сетевого запроса: не отправлять устаревшую версию.
            if ($this->article((int)$row['publication_id']) !== $article) { throw new RuntimeException('Статья изменилась во время подготовки'); }
            // Нет обложки/страницы — откладываем эту статью, не блокируя весь архив.
            if ((int)$row['is_archive'] === 1 && !$this->reserveArchive($id)) { return false; }
            $payload = $prepared['payload'] + ['cover_sha256' => $prepared['sha256']];
            $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            $same = $row['payload'] === $encoded;
            $textOnly = !empty($payload['text_only']);
            $photo = $textOnly ? '' : ($same ? $row['photo_id'] : null);
            $this->query('UPDATE vk_blog_deliveries SET payload = ?, photo_id = ?, attempts = attempts + 1, last_error = NULL WHERE id = ?',
                [$encoded, $photo, $id]);
            if (!$textOnly && !$photo) {
                $photo = $this->vk->upload($prepared['bytes'], $prepared['mime']);
                $this->query('UPDATE vk_blog_deliveries SET photo_id = ? WHERE id = ?', [$photo, $id]);
            }
            // После загрузки фото повторно проверяем доступность страницы и её обложку.
            $latest = $this->site->prepare($article);
            if ($latest['payload'] !== $prepared['payload'] || $latest['sha256'] !== $prepared['sha256'] ||
                $this->article((int)$row['publication_id']) !== $article) {
                throw new RuntimeException('Статья или обложка изменилась до отправки');
            }
            $this->query("UPDATE vk_blog_deliveries SET state = 'sending', attempted_at = ? WHERE id = ?",
                [gmdate('Y-m-d H:i:s'), $id]);
            $started = true;
            $post = $this->vk->post($payload, $photo, $row['operation_key']);
            $this->markSent($id, $post);
            return true;
        } catch (Throwable $e) {
            // Только явно отклонённый запрос можно повторить. Неизвестные API-ошибки также консервативно блокируются.
            $rejected = $e instanceof VkBlogApiError && in_array($e->vkCode,
                [5, 6, 7, 9, 14, 15, 17, 18, 27, 28, 100, 214, 219], true);
            $state = $started && !$rejected ? 'unknown' : 'pending';
            $this->query('UPDATE vk_blog_deliveries SET state = ?, last_error = ? WHERE id = ?',
                [$state, mb_substr($e->getMessage(), 0, 1000), $id]);
            ($this->notify)('vk_blog_delivery_' . $id, $e->getMessage(), ['delivery_id' => $id, 'state' => $state]);
            if ($e instanceof PDOException || ($e instanceof VkBlogApiError && $e->stopsChannel())) { throw $e; }
            return false;
        }
    }
}

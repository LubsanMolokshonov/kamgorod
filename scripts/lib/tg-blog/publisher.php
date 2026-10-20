<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/site.php';

final class TgBlogPublisher
{
    private const ELIGIBLE = "source='blog' AND status='published' AND (redirect_to_slug IS NULL OR redirect_to_slug='') AND published_at <= NOW()";
    public function __construct(private PDO $db, private TgBlogGateway $telegram, private TgBlogSiteGateway $site,
        private string $chatId, private string $baseUrl) {}

    private function query(string $sql, array $params = []): PDOStatement
    {
        $q = $this->db->prepare($sql); $q->execute($params); return $q;
    }

    public static function caption(array $article, string $url): string
    {
        $title = TgBlogSite::plain((string)$article['title']);
        $description = TgBlogSite::plain((string)($article['annotation'] ?? ''));
        if ($description === '') { $description = TgBlogSite::plain((string)($article['content'] ?? '')); }
        // Telegram считает лимиты в UTF-16; оставляем запас и не разрываем emoji.
        $units = static fn(string $s): int => intdiv(strlen(mb_convert_encoding($s, 'UTF-16LE', 'UTF-8')), 2);
        $suffix = "\n\nЧитать на сайте: " . $url;
        if ($units($title . $suffix) > 1000) { throw new RuntimeException('Заголовок и ссылка не помещаются в подпись'); }
        $available = 1000 - $units($title . "\n\n" . $suffix);
        while ($description !== '' && $units($description) > $available) {
            $description = mb_substr($description, 0, max(0, mb_strlen($description) - 10));
        }
        return $title . ($description !== '' ? "\n\n" . $description : '') . $suffix;
    }

    public static function slotDue(string $queue, DateTimeImmutable $now): bool
    {
        if (!in_array($queue, ['archive', 'new'], true)) { throw new InvalidArgumentException('Очередь: archive или new'); }
        return $now->setTimezone(new DateTimeZone('Europe/Moscow'))->format('H:i') >= ($queue === 'archive' ? '10:00' : '12:00');
    }

    public static function validatePhoto(array $photo): void
    {
        if (strlen($photo['bytes']) > 10000000) { throw new RuntimeException('Обложка превышает 10 МБ'); }
        $info = @getimagesizefromstring($photo['bytes']);
        if (!$info || min($info[0],$info[1]) < 1 || $info[0] + $info[1] > 10000 || max($info[0], $info[1]) / min($info[0], $info[1]) > 20) {
            throw new RuntimeException('Размеры обложки не подходят Telegram');
        }
    }

    public function preview(): array
    {
        $rows = $this->query('SELECT * FROM publications WHERE ' . self::ELIGIBLE . ' ORDER BY published_at,id')->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $a): array => ['publication_id' => $a['id'], 'cover' => $a['cover_image_url'],
            'caption' => self::caption($a, $this->url($a))], $rows);
    }

    private function url(array $a): string
    {
        return rtrim($this->baseUrl, '/') . '/blog/' . rawurlencode($a['slug']) . '/';
    }

    private function enqueue(string $queue): void
    {
        $this->query("INSERT IGNORE INTO tg_blog_deliveries (chat_id,publication_id,queue)
            SELECT ?,id,? FROM publications WHERE " . self::ELIGIBLE . ' ORDER BY published_at,id', [$this->chatId, $queue]);
    }

    public function initialize(): void
    {
        $this->db->beginTransaction();
        try {
            if ($this->query('SELECT chat_id FROM tg_blog_channels WHERE chat_id=?', [$this->chatId])->fetchColumn()) {
                throw new RuntimeException('Архив уже зафиксирован; повторная инициализация запрещена');
            }
            $this->query('INSERT INTO tg_blog_channels (chat_id,initialized_at) VALUES (?,UTC_TIMESTAMP())', [$this->chatId]);
            $this->enqueue('archive');
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }

    public function run(string $queue, ?DateTimeImmutable $now = null): array
    {
        $now = ($now ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('Europe/Moscow'));
        if (!self::slotDue($queue, $now)) { return ['state' => 'too_early']; }
        if (!$this->query('SELECT chat_id FROM tg_blog_channels WHERE chat_id=?', [$this->chatId])->fetchColumn()) {
            throw new RuntimeException('Сначала зафиксируйте архив: --initialize');
        }
        $day = $now->format('Y-m-d');
        // Восстановление после аварии: повтор неизвестной отправки запрещён.
        $this->query("UPDATE tg_blog_deliveries SET state='unknown',last_error='Процесс прерван во время отправки; требуется сверка' WHERE chat_id=? AND state='sending'", [$this->chatId]);
        $this->query("UPDATE tg_blog_slots SET state='unknown' WHERE chat_id=? AND state='sending'", [$this->chatId]);
        $this->enqueue('new');
        if ($this->query("SELECT id FROM tg_blog_deliveries WHERE chat_id=? AND queue=? AND state='unknown' LIMIT 1", [$this->chatId, $queue])->fetchColumn()) {
            throw new RuntimeException('Неизвестный результат предыдущей отправки: очередь остановлена до ручной сверки');
        }
        if ($this->query('SELECT delivery_id FROM tg_blog_slots WHERE chat_id=? AND queue=? AND day=?', [$this->chatId, $queue, $day])->fetchColumn()) {
            return ['state' => 'daily_limit'];
        }
        $a = $this->query("SELECT p.*,d.id AS delivery_id FROM tg_blog_deliveries d JOIN publications p ON p.id=d.publication_id
            WHERE d.chat_id=? AND d.queue=? AND d.state='pending' AND p." . self::ELIGIBLE . ' ORDER BY p.published_at,p.id LIMIT 1', [$this->chatId, $queue])->fetch(PDO::FETCH_ASSOC);
        if (!$a) { return ['state' => 'empty']; }
        $id = (int)$a['delivery_id'];
        try {
            $photo = $this->site->prepare($a);
            self::validatePhoto($photo);
            $caption = self::caption($a, $this->url($a));
            $latest = $this->query('SELECT * FROM publications WHERE id=? AND ' . self::ELIGIBLE, [$a['id']])->fetch(PDO::FETCH_ASSOC);
            foreach (['title','slug','annotation','content','cover_image_url'] as $key) {
                if (!$latest || ($latest[$key] ?? null) !== ($a[$key] ?? null)) { throw new RuntimeException('Статья изменилась во время подготовки'); }
            }
        } catch (Throwable $e) {
            $this->query('UPDATE tg_blog_deliveries SET last_error=? WHERE id=?', [$e->getMessage(), $id]);
            throw $e;
        }
        // Слот и намерение отправки фиксируются до сетевого запроса.
        $this->db->beginTransaction();
        try {
            $this->query("INSERT INTO tg_blog_slots VALUES (?,?,?,?,'sending')", [$this->chatId, $queue, $day, $id]);
            $this->query("UPDATE tg_blog_deliveries SET state='sending',attempts=attempts+1,payload=?,last_error=NULL WHERE id=?",
                [json_encode(['caption' => $caption, 'cover_sha256' => $photo['sha256']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $id]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        try {
            $messageId = $this->telegram->send($photo, $caption);
        } catch (TgBlogRejected $e) {
            $this->query("UPDATE tg_blog_deliveries SET state='pending',last_error=? WHERE id=?", [$e->getMessage(), $id]);
            $this->query("UPDATE tg_blog_slots SET state='rejected' WHERE chat_id=? AND queue=? AND day=?", [$this->chatId,$queue,$day]);
            throw $e;
        } catch (Throwable $e) {
            $this->query("UPDATE tg_blog_deliveries SET state='unknown',last_error='Нет подтверждённого результата; нужна ручная сверка' WHERE id=?", [$id]);
            $this->query("UPDATE tg_blog_slots SET state='unknown' WHERE chat_id=? AND queue=? AND day=?", [$this->chatId,$queue,$day]);
            throw new TgBlogUnknown('Результат отправки неизвестен; автоматический повтор заблокирован');
        }
        $this->db->beginTransaction();
        try {
            $this->query("UPDATE tg_blog_deliveries SET state='sent',message_id=?,sent_at=UTC_TIMESTAMP() WHERE id=?", [$messageId,$id]);
            $this->query("UPDATE tg_blog_slots SET state='sent' WHERE chat_id=? AND queue=? AND day=?", [$this->chatId,$queue,$day]);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
        return ['state' => 'sent', 'publication_id' => $a['id'], 'message_id' => $messageId];
    }
}

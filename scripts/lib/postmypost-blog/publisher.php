<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/../vk-blog/site.php';

final class PmpBlogPublisher
{
    private const ELIGIBLE = "source='blog' AND status='published' AND (redirect_to_slug IS NULL OR redirect_to_slug='')";
    private string $today;
    public function __construct(private PDO $db, private PmpBlogGateway $api, private VkBlogSiteGateway $site,
        private Closure $notify, private int $project = 368744, private int $account = 2363337,
        private int $group = 200065283, ?string $today = null)
    {
        $this->today = $today ?? (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    private function q(string $sql, array $params = []): PDOStatement
    { $q = $this->db->prepare($sql); $q->execute($params); return $q; }

    public function initialize(): void
    {
        if ($this->q('SELECT group_id FROM vk_blog_channels WHERE group_id=?', [$this->group])->fetchColumn()) { return; }
        $this->db->beginTransaction();
        try {
            $this->q('INSERT INTO vk_blog_channels(group_id,initialized_at) VALUES(?,?)', [$this->group, gmdate('Y-m-d H:i:s')]);
            $this->enqueue(true);
            $this->db->commit();
        } catch (Throwable $e) { if ($this->db->inTransaction()) { $this->db->rollBack(); } throw $e; }
    }

    private function enqueue(bool $archive = false): void
    {
        foreach ($this->q('SELECT id FROM publications WHERE ' . self::ELIGIBLE . ' ORDER BY published_at,id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            if (!$this->q('SELECT id FROM vk_blog_deliveries WHERE publication_id=? AND group_id=?', [$id, $this->group])->fetchColumn()) {
                $this->q('INSERT INTO vk_blog_deliveries(publication_id,group_id,is_archive,operation_key) VALUES(?,?,?,?)',
                    [$id, $this->group, (int)$archive, hash('sha256', 'fgos-blog:' . $this->group . ':' . $id)]);
            }
        }
    }

    private function article(int $id): array|false
    {
        return $this->q('SELECT id,source,status,title,slug,annotation,content,cover_image_url,redirect_to_slug FROM publications WHERE id=? AND ' . self::ELIGIBLE, [$id])->fetch(PDO::FETCH_ASSOC);
    }

    public function preview(): array
    {
        $rows = $this->q("SELECT p.id,p.title,p.slug,p.cover_image_url,d.state,d.is_archive FROM publications p
            LEFT JOIN vk_blog_deliveries d ON d.publication_id=p.id AND d.group_id=?
            WHERE p." . self::ELIGIBLE . " AND (d.id IS NULL OR d.state='pending')
            ORDER BY COALESCE(d.is_archive,0),p.published_at,p.id", [$this->group])->fetchAll(PDO::FETCH_ASSOC);
        return array_map(static fn(array $r): array => $r + ['url' => 'https://fgos.pro/blog/' . rawurlencode($r['slug']) . '/?utm_source=vk&utm_campaign=blog'], $rows);
    }

    private function providerRow(int $id): array
    {
        $row = $this->q('SELECT * FROM postmypost_blog_deliveries WHERE delivery_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        if ($row && ((int)$row['project_id'] !== $this->project || (int)$row['account_id'] !== $this->account)) {
            throw new RuntimeException('Postmypost: журнал привязан к другому проекту/аккаунту');
        }
        if (!$row) {
            $this->q('INSERT INTO postmypost_blog_deliveries(delivery_id,project_id,account_id) VALUES(?,?,?)', [$id, $this->project, $this->account]);
            $row = $this->q('SELECT * FROM postmypost_blog_deliveries WHERE delivery_id=?', [$id])->fetch(PDO::FETCH_ASSOC);
        }
        return $row;
    }

    private function hold(int $id, string $state, string $message): void
    {
        $this->q('UPDATE vk_blog_deliveries SET state=?,last_error=? WHERE id=?', [$state, $message, $id]);
        ($this->notify)('postmypost_blog_' . $id, $message, ['delivery_id' => $id, 'state' => $state]);
    }

    private function accept(int $id, array $post): void
    {
        if ((int)($post['id'] ?? 0) < 1 || !PmpBlogClient::detail($post, $this->account)) {
            throw new RuntimeException('Postmypost: результат не соответствует целевому аккаунту');
        }
        $this->q('UPDATE postmypost_blog_deliveries SET provider_id=?,accepted_at=COALESCE(accepted_at,?) WHERE delivery_id=?',
            [(int)$post['id'], gmdate('Y-m-d H:i:s'), $id]);
        $vk = PmpBlogClient::vkPost($post, $this->account, $this->group);
        if ($vk !== null) {
            $this->q("UPDATE vk_blog_deliveries SET state='sent',post_id=?,sent_at=COALESCE(sent_at,?),last_error=NULL WHERE id=?", [$vk, gmdate('Y-m-d H:i:s'), $id]);
            return;
        }
        if (in_array((int)($post['publication_status'] ?? -1), [2, 5], true)) {
            $this->q("UPDATE vk_blog_deliveries SET state='submitted',last_error=NULL WHERE id=?", [$id]);
        } else {
            $this->hold($id, 'blocked', 'Postmypost: запись не опубликована (статус ' . (int)($post['publication_status'] ?? -1) . '); автоматический повтор запрещён');
        }
    }

    private function reconcile(array $posts): void
    {
        $this->q("UPDATE vk_blog_deliveries SET state='unknown' WHERE group_id=? AND state='sending'", [$this->group]);
        $rows = $this->q("SELECT d.*,s.provider_id,s.request_body FROM vk_blog_deliveries d
            LEFT JOIN postmypost_blog_deliveries s ON s.delivery_id=d.id
            WHERE d.group_id=? AND d.state IN ('unknown','submitted')", [$this->group])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($row['provider_id']) {
                try { $post = $this->api->publication((int)$row['provider_id']); }
                catch (PmpBlogError $e) {
                    if ($e->http === 404) { $this->hold((int)$row['id'], 'blocked', 'Postmypost: запись удалена или недоступна; восстановление запрещено'); continue; }
                    throw $e;
                }
                $this->accept((int)$row['id'], $post);
                continue;
            }
            $request = json_decode((string)$row['request_body'], true);
            $matches = [];
            if (is_array($request)) {
                foreach ($posts as $post) { if (PmpBlogClient::exact($post, $request, $this->account)) { $matches[] = $post; } }
            }
            if (count($matches) === 1) { $this->providerRow((int)$row['id']); $this->accept((int)$row['id'], $matches[0]); }
            else { $this->hold((int)$row['id'], 'unknown', 'Postmypost: результат создания неизвестен; найдено совпадений ' . count($matches) . ', повтор запрещён'); }
        }
    }

    public function run(bool $send = false, ?int $articleId = null, ?int $limit = null): array
    {
        if (!$this->q('SELECT group_id FROM vk_blog_channels WHERE group_id=?', [$this->group])->fetchColumn()) {
            throw new RuntimeException('Сначала зафиксируйте архив --initialize');
        }
        $this->api->check();
        $posts = $this->api->publications($this->dates($send)); // API without post_date returns today only.
        $this->reconcile($posts);
        if (!$send) { return ['mode' => 'reconcile', 'created' => 0]; }
        $this->enqueue();
        $created = 0; $deferred = 0;
        $rows = $this->q("SELECT d.* FROM vk_blog_deliveries d JOIN publications p ON p.id=d.publication_id
            WHERE d.group_id=? AND d.state='pending' AND p." . self::ELIGIBLE . '
            ORDER BY d.is_archive,p.published_at,p.id', [$this->group])->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ($articleId !== null && (int)$row['publication_id'] !== $articleId) { continue; }
            if ($limit !== null && $created >= $limit) { break; }
            if ((int)$row['is_archive'] === 1 && !$this->archive((int)$row['id'], false)) { continue; }
            if ($this->deliver($row, $posts)) { $created++; } else { $deferred++; }
        }
        return ['mode' => 'send', 'created' => $created, 'deferred' => $deferred];
    }

    private function dates(bool $send): array
    {
        $dates = [$this->today, (new DateTimeImmutable($this->today))->modify('+1 day')->format('Y-m-d')];
        if ($send) {
            $rows = $this->q("SELECT p.published_at FROM publications p LEFT JOIN vk_blog_deliveries d
                ON d.publication_id=p.id AND d.group_id=? WHERE p." . self::ELIGIBLE .
                " AND (d.id IS NULL OR d.state='pending')", [$this->group])->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $value) {
                if (!$value) { continue; }
                $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
                $dates[] = $date->format('Y-m-d');
                $dates[] = $date->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d');
            }
        }
        $requests = $this->q("SELECT s.request_body FROM postmypost_blog_deliveries s JOIN vk_blog_deliveries d
            ON d.id=s.delivery_id WHERE d.group_id=? AND d.state IN ('pending','unknown','sending','submitted')",
            [$this->group])->fetchAll(PDO::FETCH_COLUMN);
        foreach ($requests as $body) {
            $request = json_decode((string)$body, true);
            if (!empty($request['post_at'])) { $dates[] = (new DateTimeImmutable($request['post_at']))->setTimezone(new DateTimeZone('Europe/Moscow'))->format('Y-m-d'); }
        }
        $dates = array_values(array_unique($dates)); sort($dates); return $dates;
    }

    private function archive(int $id, bool $reserve): bool
    {
        $row = $this->q('SELECT archive_day,archive_delivery_id FROM vk_blog_channels WHERE group_id=?', [$this->group])->fetch(PDO::FETCH_ASSOC);
        if ($row['archive_day'] === $this->today) { return (int)$row['archive_delivery_id'] === $id; }
        if ($reserve) { $this->q('UPDATE vk_blog_channels SET archive_day=?,archive_delivery_id=? WHERE group_id=?', [$this->today, $id, $this->group]); }
        return true;
    }

    private function deliver(array $row, array $posts): bool
    {
        $id = (int)$row['id']; $started = false; $createdIdKnown = false;
        try {
            $article = $this->article((int)$row['publication_id']);
            if (!$article) { return false; }
            $prepared = $this->site->prepare($article);
            if ($this->article((int)$row['publication_id']) !== $article) { throw new RuntimeException('Статья изменилась во время проверки'); }
            $payload = $prepared['payload'];
            $payload['message'] = str_replace($payload['url'], $payload['url'] . '?utm_source=vk&utm_campaign=blog', $payload['message']);
            $payload['url'] .= '?utm_source=vk&utm_campaign=blog';
            $payload['cover_sha256'] = $prepared['sha256'];
            $existing = array_values(array_filter($posts, fn(array $p): bool => PmpBlogClient::mentions($p, $payload['url'], $this->account)));
            $provider = $this->providerRow($id);
            if ($provider['provider_id']) { $this->accept($id, $this->api->publication((int)$provider['provider_id'])); return false; }
            if ($existing) {
                if (count($existing) === 1 && PmpBlogClient::vkPost($existing[0], $this->account, $this->group) !== null) {
                    $this->accept($id, $existing[0]);
                } else { $this->hold($id, 'external_hold', 'Postmypost: статья уже есть в сервисе; автоматическое создание/изменение запрещено'); }
                return false;
            }
            $this->q('UPDATE vk_blog_deliveries SET payload=?,attempts=attempts+1,last_error=NULL WHERE id=?',
                [json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            $fileId = $provider['source_sha256'] === $prepared['sha256'] ? (int)$provider['file_id'] : 0;
            if ($fileId < 1) {
                $fileId = $this->api->upload($prepared['bytes'], $prepared['mime']);
                $this->q('UPDATE postmypost_blog_deliveries SET file_id=?,source_sha256=? WHERE delivery_id=?', [$fileId, $prepared['sha256'], $id]);
            }
            $latest = $this->site->prepare($article);
            if ($latest['sha256'] !== $prepared['sha256'] || $latest['payload'] !== $prepared['payload'] ||
                $this->article((int)$row['publication_id']) !== $article) { throw new RuntimeException('Статья или обложка изменилась до отправки'); }
            if ((int)$row['is_archive'] === 1 && !$this->archive($id, true)) { return false; }
            $request = ['project_id' => $this->project, 'account_ids' => [$this->account], 'publication_status' => 5,
                'post_at' => (new DateTimeImmutable('+10 seconds', new DateTimeZone('Europe/Moscow')))->format(DATE_ATOM),
                'details' => [['account_id' => $this->account, 'publication_type' => 1, 'content' => $payload['message'], 'file_ids' => [$fileId]]]];
            $this->q('UPDATE postmypost_blog_deliveries SET request_body=? WHERE delivery_id=?',
                [json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $id]);
            $this->q("UPDATE vk_blog_deliveries SET state='sending',attempted_at=? WHERE id=?", [gmdate('Y-m-d H:i:s'), $id]);
            $started = true;
            $post = $this->api->create($request);
            $createdIdKnown = true;
            $this->q('UPDATE postmypost_blog_deliveries SET provider_id=?,accepted_at=? WHERE delivery_id=?',
                [(int)$post['id'], gmdate('Y-m-d H:i:s'), $id]);
            if (!PmpBlogClient::exact($post, $request, $this->account)) {
                // Some create responses omit details: fetch the persisted object, never create a second one.
                $post = $this->api->publication((int)$post['id']);
                if (!PmpBlogClient::exact($post, $request, $this->account)) { throw new RuntimeException('Postmypost: созданный объект требует ручной сверки'); }
            }
            $this->accept($id, $post);
            return true;
        } catch (Throwable $e) {
            // DB failure after the intent must stop the channel, preserving sending for later reconciliation.
            if ($e instanceof PDOException) { throw $e; }
            $rejected = !$createdIdKnown && $e instanceof PmpBlogError && $e->rejected();
            $this->hold($id, $started && !$rejected ? 'unknown' : 'pending', $e->getMessage());
            if ($e instanceof PmpBlogError && in_array($e->http, [401, 403, 429], true)) { throw $e; }
            return false;
        }
    }
}

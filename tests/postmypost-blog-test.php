<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/postmypost-blog/publisher.php';
function pmpCheck(bool $value, string $label): void { if (!$value) { throw new RuntimeException('FAIL: ' . $label); } echo 'OK: ' . $label . "\n"; }
function pmpFails(Closure $call, string $label): void {
    try { $call(); } catch (Throwable) { pmpCheck(true, $label); return; } throw new RuntimeException('Expected failure: ' . $label);
}
final class FakePmp implements PmpBlogGateway
{
    public array $items = []; public int $uploads = 0; public int $creates = 0;
    public bool $lost = false; public bool $denied = false; public bool $brokenRead = false;
    public ?Throwable $failure = null; public ?Closure $afterUpload = null; public bool $shortResponse = false; public bool $missing = false;
    public function check(): void { if ($this->denied) { throw new PmpBlogError(401); } }
    public array $readDates = [];
    public function publications(array $dates = []): array { $this->readDates = $dates; if ($this->brokenRead) { throw new RuntimeException('List unavailable'); } return array_values($this->items); }
    public function publication(int $id): array { if ($this->missing || !isset($this->items[$id])) { throw new PmpBlogError(404); } return $this->items[$id]; }
    public function upload(string $bytes, string $mime): int { if ($this->afterUpload) { ($this->afterUpload)(); } return ++$this->uploads; }
    public function create(array $request): array {
        if ($this->failure) { throw $this->failure; }
        $id = 100 + ++$this->creates; $details = $request['details'];
        foreach ($details as &$detail) { $detail['files'] = array_map(fn($id) => ['id' => $id], $detail['file_ids']); unset($detail['file_ids']); } unset($detail);
        $post = ['id' => $id, 'account_ids' => $request['account_ids'], 'details' => $details, 'posts' => [], 'publication_status' => 5];
        $this->items[$id] = $post;
        if ($this->lost) { throw new RuntimeException('Response lost'); }
        return $this->shortResponse ? ['id' => $id] : $post;
    }
    public function publishAll(): void {
        foreach ($this->items as $id => &$post) { $post['publication_status'] = 1; $post['posts'] = [['account_id' => 7, 'post_status' => 1, 'external_id' => (string)$id, 'url' => 'https://vk.ru/wall-42_' . $id]]; } unset($post);
    }
}
final class FakePmpSite implements VkBlogSiteGateway
{
    public bool $down = false; public ?Closure $during = null;
    public function prepare(array $a): array {
        if ($this->down || !$a['cover_image_url']) { throw new RuntimeException('Page or cover unavailable'); }
        if ($this->during) { ($this->during)(); }
        return ['payload' => VkBlogSite::payload($a, 'https://fgos.pro/blog/' . $a['slug'] . '/'),
            'sha256' => hash('sha256', $a['cover_image_url']), 'bytes' => 'original-cover', 'mime' => 'image/png'];
    }
}
function pmpFixture(): array {
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE publications(id INTEGER PRIMARY KEY,source TEXT DEFAULT 'blog',status TEXT DEFAULT 'published',title TEXT DEFAULT 'Title',slug TEXT,annotation TEXT DEFAULT 'Summary',content TEXT DEFAULT 'Body',cover_image_url TEXT DEFAULT '/cover.png',published_at TEXT,redirect_to_slug TEXT);
      CREATE TABLE vk_blog_channels(group_id INTEGER PRIMARY KEY,initialized_at TEXT,archive_day TEXT,archive_delivery_id INTEGER);
      CREATE TABLE vk_blog_deliveries(id INTEGER PRIMARY KEY AUTOINCREMENT,publication_id INTEGER,group_id INTEGER,is_archive INTEGER DEFAULT 0,state TEXT DEFAULT 'pending',operation_key TEXT UNIQUE,payload TEXT,photo_id TEXT,post_id INTEGER,attempts INTEGER DEFAULT 0,last_error TEXT,attempted_at TEXT,sent_at TEXT,UNIQUE(publication_id,group_id));
      CREATE TABLE postmypost_blog_deliveries(delivery_id INTEGER PRIMARY KEY,project_id INTEGER,account_id INTEGER,provider_id INTEGER,file_id INTEGER,source_sha256 TEXT,request_body TEXT,accepted_at TEXT,UNIQUE(project_id,account_id,provider_id));");
    return [$db, new FakePmp(), new FakePmpSite()];
}
function pmpArticle(PDO $db, int $id): void {
    $q = $db->prepare('INSERT INTO publications(id,slug,published_at) VALUES(?,?,?)'); $q->execute([$id, 'article-' . $id, '2026-10-' . sprintf('%02d', $id)]);
}
function pmpPublisher(array $f, string $day = '2026-10-21'): PmpBlogPublisher {
    return new PmpBlogPublisher($f[0], $f[1], $f[2], static function () {}, 10, 7, 42, $day);
}

$f = pmpFixture(); [$db, $api] = $f; pmpArticle($db, 1); pmpArticle($db, 2); $p = pmpPublisher($f);
pmpCheck(count($p->preview()) === 2 && !$db->query('SELECT COUNT(*) FROM vk_blog_deliveries')->fetchColumn(), 'Preview is read-only');
pmpFails(fn() => $p->run(true), 'Cannot send before archive initialization');
$p->initialize(); $p->initialize(); pmpArticle($db, 3); pmpArticle($db, 4); pmpArticle($db, 5); $db->exec("UPDATE publications SET status='draft' WHERE id=5");
$p->run(true);
pmpCheck(in_array('2026-10-01', $api->readDates, true) && in_array('2026-10-21', $api->readDates, true), 'Explicit dates include archive and current day; API default today is not used');
pmpCheck($api->creates === 3, 'All new published articles plus one oldest archive');
$contents = array_map(fn($r) => $r['details'][0]['content'], array_values($api->items));
pmpCheck(str_contains($contents[0], 'article-3/') && str_contains($contents[1], 'article-4/') && str_contains($contents[2], 'article-1/'), 'New articles precede chronological archive');
pmpCheck(str_contains($contents[0], '?utm_source=vk&utm_campaign=blog'), 'VK UTM parameters included');
pmpCheck((int)$db->query("SELECT COUNT(*) FROM vk_blog_deliveries WHERE state='submitted'")->fetchColumn() === 3, 'Provider acceptance is not mistaken for VK publication');
$p->run(true); pmpCheck($api->creates === 3, 'Repeat run does not duplicate pending service publications or archive');
$api->publishAll(); $p->run(false);
pmpCheck((int)$db->query("SELECT COUNT(*) FROM vk_blog_deliveries WHERE state='sent' AND post_id IS NOT NULL")->fetchColumn() === 3, 'Reconciliation records actual VK IDs');
$db->exec("UPDATE publications SET title='Edited',slug='edited',published_at='2030-01-01' WHERE id=3");
$p->run(true); pmpCheck($api->creates === 3, 'Edits do not resend sent article');
$api->items = []; $p->run(true); pmpCheck($api->creates === 3, 'Manually deleted published posts are not restored');
pmpPublisher($f, '2026-10-22')->run(true); pmpCheck($api->creates === 4, 'Next day advances one archive');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->lost = true;
$p->run(true); pmpCheck($db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'Lost create response becomes unknown');
$api->lost = false; $p->run(true); pmpCheck($api->creates === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'submitted', 'Exact text and uploaded file recover lost response without resend');
$api->missing = true; $p->run(false); pmpCheck($db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'blocked', 'Deleted provider object permanently blocks recreation');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->lost = true;
$p->run(true); $api->items = []; $api->lost = false; $p->run(true); $p->run(true);
pmpCheck($api->creates === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'No match after ambiguous response never permits resend');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->failure = new PmpBlogError(422);
$p->run(true); $api->failure = null; $p->run(true);
pmpCheck($api->creates === 1 && $api->uploads === 1, 'Explicit rejection can retry with same saved file');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1);
$db->exec("CREATE TRIGGER fail_intent BEFORE UPDATE ON vk_blog_deliveries WHEN NEW.state='sending' BEGIN SELECT RAISE(ABORT,'disk unavailable'); END;");
pmpFails(fn() => $p->run(true), 'Cannot save intent stops before create');
pmpCheck($api->creates === 0, 'No provider mutation without durable intent');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1);
$db->exec("CREATE TRIGGER fail_ack BEFORE UPDATE ON vk_blog_deliveries WHEN NEW.state='submitted' BEGIN SELECT RAISE(ABORT,'DB lost'); END;");
pmpFails(fn() => $p->run(true), 'Lost DB acknowledgement stops channel');
$db->exec('DROP TRIGGER fail_ack'); $p->run(true);
pmpCheck($api->creates === 1, 'Recovery after DB save failure does not duplicate');

$f = pmpFixture(); [$db, $api, $site] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $site->down = true;
$p->run(true); pmpCheck($api->creates === 0 && $api->uploads === 0, 'Website outage defers without external writes');
$site->down = false; $api->afterUpload = fn() => $db->exec("UPDATE publications SET status='draft' WHERE id=1");
$p->run(true); pmpCheck($api->creates === 0, 'Unpublishing during upload blocks create');

$f = pmpFixture(); [$db, $api] = $f; pmpArticle($db, 1); pmpArticle($db, 2); $p = pmpPublisher($f); $p->initialize();
$db->exec("UPDATE publications SET cover_image_url='' WHERE id=1"); $p->run(true);
pmpCheck($api->creates === 1 && str_contains($api->items[101]['details'][0]['content'], 'article-2/'), 'Missing archive cover does not consume quota before validation');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1);
$api->items[77] = ['id' => 77, 'account_ids' => [7], 'details' => [['publication_type' => 1, 'content' => 'RSS draft https://fgos.pro/blog/article-1/?utm_source=vk']], 'publication_status' => 4, 'posts' => []];
$p->run(true); pmpCheck($api->creates === 0 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'external_hold', 'Existing RSS draft blocks duplicate API post and is not changed');
$p->run(false); pmpCheck($db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'external_hold', 'Read-only reconciliation leaves an existing draft unchanged');
$api->publishAll(); $p->run(false);
pmpCheck($api->creates === 0 && $db->query('SELECT post_id FROM vk_blog_deliveries')->fetchColumn() == 77, 'Manual publication of existing RSS draft is acknowledged automatically without creating a post');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->lost = true; $p->run(true);
$api->items[102] = $api->items[101]; $api->items[102]['id'] = 102; $api->lost = false; $p->run(true);
pmpCheck($api->creates === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'Multiple exact matches remain blocked');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->shortResponse = true; $api->missing = true;
$p->run(true); pmpCheck((int)$db->query('SELECT provider_id FROM postmypost_blog_deliveries')->fetchColumn() === 101, 'Create ID is persisted before fallback GET');
pmpCheck($db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', '404 after successful create is not treated as permission to resend');
$api->missing = false; $p->run(true); pmpCheck($api->creates === 1, 'Known accepted ID prevents second create after failed fetch');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $api->brokenRead = true;
pmpFails(fn() => $p->run(true), 'Incomplete external listing stops sending'); pmpCheck($api->creates === 0, 'Provider read outage causes no post');

$f = pmpFixture(); [$db, $api] = $f; $p = pmpPublisher($f); $p->initialize(); pmpArticle($db, 1); $p->run(true);
$api->items[101]['details'][0]['content'] = 'Manually changed'; $p->run(false); $p->run(true);
pmpCheck($api->creates === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'blocked', 'Changed accepted object is held without modifying or recreating it');

$calls = [];
$client = new PmpBlogClient('secret', 10, 7, 42, function ($method, $path) use (&$calls): array {
    $calls[] = $path;
    return ['data' => [['id' => count($calls)]], 'pages' => ['total_pages' => 2]];
});
pmpCheck(count($client->publications(['2026-10-21'])) === 2 && count($calls) === 2, 'Reconciliation reads every provider page');
pmpCheck(str_contains($calls[0], 'post_date=2026-10-21') && str_contains($calls[0], 'publication_status='), 'Provider list explicitly selects date and all statuses');
pmpCheck(!PmpBlogClient::mentions(['account_ids' => [8], 'details' => [['content' => 'https://fgos.pro/blog/article-1/']]], 'https://fgos.pro/blog/article-1/', 7), 'Other account posts cannot match');
$wrong = new PmpBlogClient('secret', 10, 7, 42, fn() => ['id' => 999]);
pmpFails(fn() => $wrong->publication(100), 'GET response with a different publication ID is rejected');
echo "Postmypost blog checks passed\n";

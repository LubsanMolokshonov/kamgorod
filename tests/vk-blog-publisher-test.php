<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/vk-blog/publisher.php';

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
    echo "OK: {$message}\n";
}
function fails(Closure $fn, string $message): void
{
    try { $fn(); } catch (Throwable) { check(true, $message); return; }
    throw new RuntimeException('Ожидалась ошибка: ' . $message);
}
final class FakeVkBlog implements VkBlogGateway
{
    public array $posts = [];
    public int $uploads = 0;
    public ?Throwable $failure = null;
    public bool $loseResponse = false;
    public bool $hidden = false;
    public bool $deny = false;
    public function preflight(): void { if ($this->deny) { throw new VkBlogApiError(5); } }
    public function upload(string $bytes, string $mime): string { return 'photo-42_' . ++$this->uploads; }
    public function post(array $payload, string $photo, string $key): int
    {
        if ($this->failure) { throw $this->failure; }
        $id = count($this->posts) + 100;
        $this->posts[$id] = compact('payload', 'photo', 'key');
        if ($this->loseResponse) { throw new RuntimeException('Потерян ответ'); }
        return $id;
    }
    public function reconcile(array $payload, string $photo): ?int
    {
        if ($this->hidden) { return null; }
        $found = [];
        foreach ($this->posts as $id => $p) {
            if ($p['payload'] === $payload && $p['photo'] === $photo) { $found[] = $id; }
        }
        return count($found) === 1 ? $found[0] : null;
    }
}
final class FakeBlogSite implements VkBlogSiteGateway
{
    public bool $down = false;
    public int $calls = 0;
    public ?int $failOnCall = null;
    public ?Closure $during = null;
    public function prepare(array $article): array
    {
        if (++$this->calls === $this->failOnCall || $this->down || empty($article['cover_image_url'])) { throw new RuntimeException('Нет сайта/обложки'); }
        if ($this->during) { ($this->during)(); }
        return ['payload' => VkBlogSite::payload($article, 'https://fgos.pro/blog/' . $article['slug'] . '/'),
            'sha256' => hash('sha256', $article['cover_image_url']), 'bytes' => 'same-cover', 'mime' => 'image/png'];
    }
}
function fixture(): array
{
    $db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec("CREATE TABLE publications (id INTEGER PRIMARY KEY, source TEXT DEFAULT 'blog', status TEXT DEFAULT 'published',
        title TEXT DEFAULT 'Заголовок', slug TEXT, annotation TEXT DEFAULT 'Анонс', content TEXT DEFAULT '<p>Текст</p>',
        cover_image_url TEXT DEFAULT '/cover.png', published_at TEXT, redirect_to_slug TEXT);
        CREATE TABLE vk_blog_channels (group_id INTEGER PRIMARY KEY, initialized_at TEXT, archive_day TEXT, archive_delivery_id INTEGER);
        CREATE TABLE vk_blog_deliveries (id INTEGER PRIMARY KEY AUTOINCREMENT, publication_id INTEGER, group_id INTEGER,
        is_archive INTEGER DEFAULT 0, state TEXT DEFAULT 'pending', operation_key TEXT UNIQUE, payload TEXT, photo_id TEXT,
        post_id INTEGER, attempts INTEGER DEFAULT 0, last_error TEXT, attempted_at TEXT, sent_at TEXT,
        UNIQUE(publication_id,group_id));");
    $vk = new FakeVkBlog();
    $site = new FakeBlogSite();
    $notice = static function (string $key, string $message, array $context): void {};
    return [$db, $vk, $site, $notice];
}
function article(PDO $db, int $id, string $status = 'published', string $source = 'blog'): void
{
    $q = $db->prepare('INSERT INTO publications (id,slug,published_at,status,source) VALUES (?,?,?,?,?)');
    $q->execute([$id, 'article-' . $id, sprintf('2026-10-%02d 08:00:00', $id), $status, $source]);
}
function publisher(array $f, string $day = '2026-10-19'): VkBlogPublisher
{
    return new VkBlogPublisher($f[0], $f[1], $f[2], 42, $f[3], $day);
}

$f = fixture(); [$db, $vk, $site] = $f;
article($db, 1); article($db, 2); article($db, 3, 'draft'); article($db, 4, 'published', 'user');
$db->exec("UPDATE publications SET redirect_to_slug='replacement' WHERE id=2");
$p = publisher($f);
check(count($p->preview('https://fgos.pro')) === 1, 'предпросмотр исключает черновики, чужой раздел и редиректы');
check((int)$db->query('SELECT count(*) FROM vk_blog_deliveries')->fetchColumn() === 0 && !$vk->posts, 'предпросмотр не меняет базу и VK');
fails(fn() => $p->run(), 'отправка до фиксации архива запрещена');
$db->exec('UPDATE publications SET redirect_to_slug=NULL WHERE id=2');
$p->initialize();
fails(fn() => $p->initialize(), 'повторная инициализация не сбрасывает историю');
article($db, 5); article($db, 6);
$p->run();
check(count($vk->posts) === 3, 'все новые и одна архивная статья');
$urls = array_column(array_column($vk->posts, 'payload'), 'url');
check(str_contains($urls[0], 'article-5') && str_contains($urls[1], 'article-6') && str_contains($urls[2], 'article-1'), 'новые первыми, архив от старых к новым');
$p->run();
check(count($vk->posts) === 3, 'повторный запуск не отправляет второй архив');
$db->exec("UPDATE publications SET title='Правка', slug='new-slug', published_at='2027-01-01' WHERE id=1");
$p->run();
check(count($vk->posts) === 3, 'редактирование текста, slug и даты не создаёт новый пост');
publisher($f, '2026-10-20')->run();
check(count($vk->posts) === 4, 'на следующий день отправляется следующий архив');
$db->exec("UPDATE publications SET status='draft' WHERE id=1");
$p->run();
$db->exec("UPDATE publications SET status='published' WHERE id=1");
$p->run();
check(count($vk->posts) === 4, 'снятие и возвращение статьи не создаёт повтор');
$vk->posts = [];
$p->run();
check(!$vk->posts, 'ручное удаление записей VK не приводит к восстановлению');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$vk->loseResponse = true;
$p->run();
check($db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'потеря ответа после создания поста блокирует повтор');
$vk->loseResponse = false;
$p->run();
check(count($vk->posts) === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'sent', 'сверка восстанавливает ID без повторного поста');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$vk->failure = new RuntimeException('Таймаут');
$p->run(); $vk->failure = null;
$p->run(); publisher($f, '2026-10-20')->run();
check(!$vk->posts && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'отсутствие поста при сверке не разрешает повтор');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$vk->failure = new VkBlogApiError(6); $p->run(); $vk->failure = null; $p->run();
check(count($vk->posts) === 1, 'явный отказ VK до создания допускает повтор');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$db->exec("CREATE TRIGGER fail_sent BEFORE UPDATE OF state ON vk_blog_deliveries
    WHEN NEW.state = 'sent' BEGIN SELECT RAISE(ABORT, 'DB write failed'); END");
fails(fn() => $p->run(), 'сбой записи ID немедленно останавливает процесс');
check(count($vk->posts) === 1 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown', 'после сбоя сохранения созданный пост остаётся неизвестным');
$db->exec('DROP TRIGGER fail_sent');
$p->run();
check(count($vk->posts) === 1 && $db->query('SELECT post_id FROM vk_blog_deliveries')->fetchColumn() == 100, 'восстановление после сбоя базы не дублирует пост');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$site->down = true; $p->run();
check(!$vk->posts && $vk->uploads === 0, 'недоступный сайт не вызывает отправку/загрузку');
$site->down = false;
$db->exec("UPDATE publications SET cover_image_url='' WHERE id=1");
$p->run(); check(!$vk->posts, 'без собственной обложки статья остаётся в очереди');
$db->exec("UPDATE publications SET cover_image_url='/cover.png' WHERE id=1");
$site->during = static fn() => $db->exec("UPDATE publications SET status='draft' WHERE id=1");
$p->run(); check(!$vk->posts, 'снятие с публикации во время проверки блокирует пост');
$site->during = null;
$db->exec("UPDATE publications SET status='published' WHERE id=1");
$p->run(); check(count($vk->posts) === 1, 'после восстановления статья отправляется');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); article($db, 1); article($db, 2); $p->initialize();
$site->down = true; $p->run(); $site->down = false; $p->run();
check(count($vk->posts) === 1, 'ошибка первой архивной не позволяет вторую в тот же день');
$db->exec("UPDATE vk_blog_deliveries SET state='sending' WHERE publication_id=2");
$p->run();
check(count($vk->posts) === 1 && $db->query('SELECT state FROM vk_blog_deliveries WHERE publication_id=2')->fetchColumn() === 'unknown', 'остаток sending после остановки процесса не переотправляется');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1); $vk->deny = true;
fails(fn() => $p->run(), 'истёкший токен останавливает канал');
check(!$vk->posts, 'при ошибке доступа постов нет');
$vk->deny = false;
$db->exec("CREATE TRIGGER fail_intent BEFORE UPDATE OF state ON vk_blog_deliveries
    WHEN NEW.state='sending' BEGIN SELECT RAISE(ABORT, 'intent failed'); END");
fails(fn() => $p->run(), 'нельзя отправлять без сохранения намерения');
check(!$vk->posts, 'при сбое базы до wall.post пост не создаётся');
$db->exec('DROP TRIGGER fail_intent');
$p->run();
check(count($vk->posts) === 1, 'безопасное продолжение после сбоя до отправки');

$payload = VkBlogSite::payload(['title'=>'<b>Название</b>', 'annotation'=>'', 'content'=>'<p>Первый</p><p>второй</p>', 'cover_image_url'=>'/same.webp'], 'https://fgos.pro/blog/a/');
check(str_contains($payload['message'], "Первый второй") && $payload['cover_url'] === '/same.webp', 'анонс из текста и исходный путь картинки');
check(VkBlogSite::plain('<script>bad()</script><p>Текст &amp; пример</p>') === 'Текст & пример', 'очистка HTML');
$image = imagecreatetruecolor(12, 8);
imagefill($image, 0, 0, imagecolorallocate($image, 20, 80, 150));
ob_start(); imagepng($image); $png = ob_get_clean();
check(VkBlogSite::image($png)['bytes'] === $png, 'PNG передаётся исходными байтами');
ob_start(); imagejpeg($image); $jpeg = ob_get_clean();
check(VkBlogSite::image($jpeg)['bytes'] === $jpeg, 'JPEG передаётся исходными байтами');
if (function_exists('imagewebp')) {
    ob_start(); imagewebp($image, null, IMG_WEBP_LOSSLESS); $webp = ob_get_clean();
    $converted = VkBlogSite::image($webp);
    $before = imagecreatefromstring($webp); $after = imagecreatefromstring($converted['bytes']);
    check(imagesx($after) === 12 && imagesy($after) === 8 && imagecolorat($before, 2, 2) === imagecolorat($after, 2, 2),
        'WebP преобразуется без изменения размера и пикселей');
}
fails(fn() => VkBlogSite::image('not an image'), 'поддельное изображение отклоняется');
$post = ['from_id'=>-42, 'text'=>$payload['message'], 'attachments'=>[['photo'=>['owner_id'=>-42,'id'=>7]]]];
check(VkBlogClient::matches($post, $payload, 'photo-42_7', 42), 'сверка требует точного текста и ID картинки');
$post['from_id'] = 123;
check(!VkBlogClient::matches($post, $payload, 'photo-42_7', 42), 'чужой автор не засчитывается');
$post['from_id'] = -42;
check(!VkBlogClient::matches($post, $payload, 'photo-42_8', 42), 'другая картинка не засчитывается');
$f = fixture(); [$db, $vk, $site] = $f;
article($db, 1); article($db, 2);
$db->exec("UPDATE publications SET cover_image_url='' WHERE id=1");
$p = publisher($f); $p->initialize(); $p->run();
check(count($vk->posts) === 1 && str_contains(current($vk->posts)['payload']['url'], 'article-2'),
    'архивная статья без обложки не блокирует другие статьи');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$site->failOnCall = 2; $p->run();
check(!$vk->posts && $vk->uploads === 1, 'недоступность сайта после загрузки фото блокирует wall.post');
$site->failOnCall = null; $p->run();
check(count($vk->posts) === 1 && $vk->uploads === 1, 'после восстановления используется уже сохранённое фото');

$f = fixture(); [$db, $vk, $site] = $f;
$p = publisher($f); $p->initialize(); article($db, 1);
$vk->loseResponse = true; $p->run();
$vk->posts[101] = $vk->posts[100];
$vk->loseResponse = false; $p->run();
check(count($vk->posts) === 2 && $db->query('SELECT state FROM vk_blog_deliveries')->fetchColumn() === 'unknown',
    'неоднозначная сверка не выбирает случайный пост и не создаёт новый');
$vk->posts = []; $p->run();
check(!$vk->posts, 'удаление неизвестного поста не разрешает автоматическое восстановление');
$f = fixture(); [$db, $vk, $site] = $f;
$db->exec('ALTER TABLE publications ADD COLUMN views_count INTEGER DEFAULT 0');
$p = publisher($f); $p->initialize(); article($db, 1);
$site->during = static fn() => $db->exec('UPDATE publications SET views_count=views_count+1 WHERE id=1');
$p->run();
check(count($vk->posts) === 1, 'изменение счётчика просмотров при чтении страницы не блокирует отправку');
echo "Все проверки пройдены\n";

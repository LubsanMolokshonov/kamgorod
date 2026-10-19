<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../scripts/lib/vk-blog/publisher.php';

if (!in_array(DB_HOST, ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Этот тест разрешён только с локальной MySQL');
}
function mysqlCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo 'OK: ' . $label . "\n";
}
$dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
$options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false];
$db = new PDO($dsn, DB_USER, DB_PASS, $options);
$second = new PDO($dsn, DB_USER, DB_PASS, $options);
$key = 'vk_blog_test_' . bin2hex(random_bytes(8));
try {
    $q = $db->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$key]);
    mysqlCheck((int)$q->fetchColumn() === 1, 'MySQL: первый процесс получает блокировку');
    $q = $second->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$key]);
    mysqlCheck((int)$q->fetchColumn() === 0, 'MySQL: второй процесс не может начать отправку');
} finally {
    $q = $db->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$key]);
}
$q = $second->prepare('SELECT GET_LOCK(?, 0)'); $q->execute([$key]);
mysqlCheck((int)$q->fetchColumn() === 1, 'MySQL: после освобождения следующий процесс может работать');
$q = $second->prepare('SELECT RELEASE_LOCK(?)'); $q->execute([$key]);

// Только TEMPORARY TABLE: существующие таблицы сайта не меняются и скрыты в этой сессии.
$sql = file_get_contents(__DIR__ . '/../database/migrations/192_vk_blog_delivery.sql');
$sql = str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $sql);
foreach (explode(';', $sql) as $statement) {
    if (trim($statement) !== '') { $db->exec($statement); }
}
$db->exec("CREATE TEMPORARY TABLE publications (
    id BIGINT PRIMARY KEY, source VARCHAR(30), status VARCHAR(30), title VARCHAR(255), slug VARCHAR(255),
    annotation TEXT, content TEXT, cover_image_url TEXT, published_at DATETIME, redirect_to_slug VARCHAR(255)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$insert = $db->prepare("INSERT INTO publications VALUES (?, 'blog', 'published', 'Заголовок', ?, 'Анонс',
    '<p>Текст</p>', '/cover.png', '2026-10-01', NULL)");
$insert->execute([1, 'first']); $insert->execute([2, 'second']);
$vk = new class implements VkBlogGateway {
    public array $posts = [];
    public function preflight(): void {}
    public function upload(string $bytes, string $mime): string { return 'photo-42_1'; }
    public function post(array $payload, string $photo, string $key): int {
        $this->posts[] = $payload['url']; return count($this->posts);
    }
    public function reconcile(array $payload, string $photo): ?int { return null; }
};
$site = new class implements VkBlogSiteGateway {
    public function prepare(array $a): array {
        return ['payload'=>VkBlogSite::payload($a, 'https://fgos.pro/blog/' . $a['slug'] . '/'),
            'sha256'=>'hash', 'bytes'=>'source', 'mime'=>'image/png'];
    }
};
$p = new VkBlogPublisher($db, $vk, $site, 42, static function (): void {}, '2026-10-19');
$p->initialize();
mysqlCheck((int)$db->query('SELECT COUNT(*) FROM vk_blog_deliveries WHERE is_archive=1')->fetchColumn() === 2,
    'MySQL: миграция и транзакционная фиксация архива');
$insert->execute([3, 'new']);
$p->run(); $p->run();
mysqlCheck(count($vk->posts) === 2 && str_contains($vk->posts[0], '/new/') && str_contains($vk->posts[1], '/first/'),
    'MySQL: новые первыми, один архив в сутки и отсутствие дублей');
try {
    $db->exec("INSERT INTO vk_blog_deliveries (publication_id, group_id, operation_key) VALUES (1,42,'other-key')");
    throw new RuntimeException('Уникальность нарушена');
} catch (PDOException $e) {
    mysqlCheck($e->getCode() === '23000', 'MySQL: уникальная пара статья/сообщество');
}
$db->exec("UPDATE publications SET title='Правка', slug='changed' WHERE id=1");
$p->run();
mysqlCheck(count($vk->posts) === 2, 'MySQL: правки не создают новую отправку');
echo "MySQL: все проверки пройдены; постоянные таблицы не менялись\n";

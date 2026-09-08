<?php
/** Изолированные проверки публикации; не подключаются к конфигу проекта. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/blog-post.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
function check($condition, string $message): void {
    if (!$condition) { throw new RuntimeException($message); }
    echo "OK: {$message}\n";
}
function rejects(callable $action, string $message): void {
    try { $action(); } catch (Throwable $e) { echo "OK: {$message}\n"; return; }
    throw new RuntimeException('Ожидался отказ: ' . $message);
}
$root = sys_get_temp_dir() . '/blog-publisher-' . bin2hex(random_bytes(6));
mkdir($root . '/assets/images/blog', 0700, true);
try {
    file_put_contents($root . '/article.html', '<p>Полезная статья.</p><h2>Пример</h2><p><a href="https://example.org" target="_blank" rel="noopener noreferrer">Текст.</a></p>');
    $image = imagecreatetruecolor(800, 400);
    imagejpeg($image, $root . '/assets/images/blog/cover.jpg');
    imagedestroy($image);
    $input = ['title' => 'Тест', 'slug' => 'test-article', 'content-file' => $root . '/article.html',
        'cover-image' => '/assets/images/blog/cover.jpg', 'tags' => 'methodology'];
    $package = blogPostPackage($input, $root);
    check(strlen($package['sha256']) === 64, 'валидный комплект');
    rejects(fn() => blogPostPackage(array_merge($input, ['content-file' => $root . '/absent']), $root), 'отсутствующий HTML');
    rejects(fn() => blogPostPackage(array_merge($input, ['cover-image' => '/assets/images/blog/absent.jpg']), $root), 'отсутствующая обложка');
    file_put_contents($root . '/assets/images/blog/fake.jpg', 'not an image');
    rejects(fn() => blogPostPackage(array_merge($input, ['cover-image' => '/assets/images/blog/fake.jpg']), $root), 'ложное расширение обложки');
    rejects(fn() => blogPostPackage(array_merge($input, ['slug' => '../bad']), $root), 'неверный slug');
    $content = file_get_contents($root . '/article.html');
    file_put_contents($root . '/article.html', '<p onclick="alert(1)">Текст</p>');
    rejects(fn() => blogPostPackage($input, $root), 'активный HTML');
    file_put_contents($root . '/article.html', '<p><a href="javascript:alert(1)">Текст</a></p>');
    rejects(fn() => blogPostPackage($input, $root), 'опасная ссылка');
    file_put_contents($root . '/article.html', '<p><a href="https://example.org">Текст</a></p>');
    rejects(fn() => blogPostPackage($input, $root), 'ссылка без безопасного открытия');
    file_put_contents($root . '/article.html', $content . '<p>Правка.</p>');
    check($package['sha256'] !== blogPostPackage($input, $root)['sha256'], 'правка текста отменяет хеш');
    file_put_contents($root . '/article.html', $content);
    check($package['sha256'] !== blogPostPackage(array_merge($input, ['meta-title' => 'Правка']), $root)['sha256'], 'правка метаданных отменяет хеш');

    $coverBytes = file_get_contents($root . '/assets/images/blog/cover.jpg');
    $image = imagecreatetruecolor(800, 400);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
    imagejpeg($image, $root . '/assets/images/blog/cover.jpg');
    imagedestroy($image);
    check($package['sha256'] !== blogPostPackage($input, $root)['sha256'], 'правка обложки отменяет хеш');
    file_put_contents($root . '/assets/images/blog/cover.jpg', $coverBytes);
    rejects(fn() => blogPostPackage(array_merge($input, ['noindex' => 'false']), $root), 'строковый noindex отклонён');

    // SQLite проверяет реальные CRUD/rollback. GET_LOCK эмулируется только здесь;
    // поведение MySQL дополнительно проверяется при BLOG_TEST_MYSQL=1 в одноразовом контейнере.
    if (getenv('BLOG_TEST_MYSQL') === '1') {
        $pdo = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE DATABASE editorial_publisher_test');
        $pdo->exec('USE editorial_publisher_test');
        $idType = 'INT PRIMARY KEY AUTO_INCREMENT';
    } else {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->sqliteCreateFunction('GET_LOCK', fn($key, $timeout) => 1, 2);
        $pdo->sqliteCreateFunction('RELEASE_LOCK', fn($key) => 1, 1);
        $idType = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    }
    $pdo->exec("CREATE TABLE publications (id {$idType}, user_id INT, title TEXT, annotation TEXT,
        content TEXT, file_path TEXT, file_original_name TEXT, file_size INT, file_type TEXT,
        publication_type_id INT, slug VARCHAR(255) UNIQUE, meta_title TEXT, meta_description TEXT,
        noindex INT, source TEXT, status TEXT, certificate_status TEXT, published_at TEXT,
        cover_image_url TEXT, cover_status TEXT)");
    $pdo->exec('CREATE TABLE users (id INT PRIMARY KEY, email VARCHAR(255), publications_count INT DEFAULT 0)');
    $pdo->exec('CREATE TABLE publication_types (id INT PRIMARY KEY, slug VARCHAR(255))');
    $pdo->exec('CREATE TABLE publication_tags (id INT PRIMARY KEY, slug VARCHAR(255))');
    $pdo->exec('CREATE TABLE publication_tag_relations (publication_id INT, tag_id INT)');
    $pdo->exec("INSERT INTO users (id,email) VALUES (1,'blog@fgos.pro')");
    $pdo->exec("INSERT INTO publication_types VALUES (1,'article')");
    $pdo->exec("INSERT INTO publication_tags VALUES (1,'methodology')");
    blogPostReferences($pdo, $package);
    check((int)$pdo->query('SELECT COUNT(*) FROM publications')->fetchColumn() === 0, 'dry-run не создаёт записей');
    $id = blogPostPublish($pdo, $package);
    $row = $pdo->query('SELECT * FROM publications')->fetch(PDO::FETCH_ASSOC);
    check($row['source'] === 'blog' && $row['status'] === 'published' && $row['cover_status'] === 'done', 'текст и обложка опубликованы вместе');
    check((int)$pdo->query('SELECT COUNT(*) FROM publication_tag_relations')->fetchColumn() === 1, 'тег сохранён');
    rejects(fn() => blogPostPublish($pdo, $package), 'повторный slug не перезаписывается');
    check((int)$pdo->query('SELECT COUNT(*) FROM publications')->fetchColumn() === 1, 'дубликат отсутствует');
    $updated = array_merge($package, ['title' => 'Обновлённый тест']);
    $updatedId = blogPostUpdate($pdo, $updated);
    check($updatedId === $id, 'обновляется существующая blog-статья');
    check($pdo->query('SELECT title FROM publications WHERE id=' . (int)$id)->fetchColumn() === 'Обновлённый тест', 'новый заголовок сохранён');
    $pdo->exec("UPDATE publications SET source='upload' WHERE id=" . (int)$id);
    rejects(fn() => blogPostUpdate($pdo, $updated), 'чужая публикация не обновляется');
    $pdo->exec("UPDATE publications SET source='blog' WHERE id=" . (int)$id);
    $second = array_merge($package, ['slug' => 'rollback-article']);
    if (getenv('BLOG_TEST_MYSQL') === '1') {
        $pdo->exec("CREATE TRIGGER fail_cover BEFORE UPDATE ON publications FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='test cover failure'");
    } else {
        $pdo->exec("CREATE TRIGGER fail_cover BEFORE UPDATE OF cover_image_url ON publications BEGIN SELECT RAISE(ABORT, 'test cover failure'); END");
    }
    rejects(fn() => blogPostPublish($pdo, $second), 'сбой записи обложки');
    check((int)$pdo->query('SELECT COUNT(*) FROM publications')->fetchColumn() === 1, 'сбой обложки откатывает статью');
    check((int)$pdo->query('SELECT COUNT(*) FROM publication_tag_relations')->fetchColumn() === 1, 'сбой обложки откатывает теги');
    check((int)$pdo->query('SELECT publications_count FROM users WHERE id=1')->fetchColumn() === 1, 'сбой обложки откатывает счётчик автора');
    $pdo->exec('DROP TRIGGER fail_cover');
    $id = blogPostPublish($pdo, $second);
    check($id > 0, 'замок освобождён после ошибки');
    if (getenv('BLOG_TEST_MYSQL') === '1') { $pdo->exec('DROP DATABASE editorial_publisher_test'); }
} finally {
    foreach (glob($root . '/assets/images/blog/*') as $file) { unlink($file); }
    unlink($root . '/article.html');
    rmdir($root . '/assets/images/blog'); rmdir($root . '/assets/images'); rmdir($root . '/assets'); rmdir($root);
}

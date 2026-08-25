<?php
/**
 * CLI скрипт: добавить статью в блог (source='blog').
 *
 * Запуск:
 *   php scripts/add-blog-post.php --title="Заголовок" --content-file=path/to/article.html [опции]
 *
 * Опции:
 *   --title=            (обязательно) заголовок статьи
 *   --content-file=     (обязательно) путь к файлу с HTML-содержимым статьи
 *   --annotation=       краткое описание (если не задано — берётся начало content)
 *   --type=             slug типа публикации (methodology|article|research|program|
 *                       presentation|masterclass|project|experience), по умолчанию article
 *   --slug=             URL-слаг (если не задан — генерируется из title)
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';

function blogPostOpt(string $name, ?string $default = null): ?string {
    foreach ($GLOBALS['argv'] as $arg) {
        if (strpos($arg, "--{$name}=") === 0) {
            return substr($arg, strlen($name) + 3);
        }
    }
    return $default;
}

$title = blogPostOpt('title');
$contentFile = blogPostOpt('content-file');
$typeSlug = blogPostOpt('type', 'article');
$slug = blogPostOpt('slug');
$annotation = blogPostOpt('annotation');

if (!$title || !$contentFile) {
    fwrite(STDERR, "Usage: php scripts/add-blog-post.php --title=\"...\" --content-file=path/to/article.html [--annotation=\"...\"] [--type=article] [--slug=custom-slug]\n");
    exit(1);
}

if (!file_exists($contentFile)) {
    fwrite(STDERR, "Content file not found: {$contentFile}\n");
    exit(1);
}

$content = file_get_contents($contentFile);
if ($annotation === null) {
    $annotation = mb_substr(trim(strip_tags($content)), 0, 300);
}

$typeRow = $db->prepare("SELECT id FROM publication_types WHERE slug = ?");
$typeRow->execute([$typeSlug]);
$type = $typeRow->fetch(PDO::FETCH_ASSOC);
if (!$type) {
    fwrite(STDERR, "Unknown publication type slug: {$typeSlug}\n");
    exit(1);
}

$authorRow = $db->prepare("SELECT id FROM users WHERE email = ?");
$authorRow->execute(['blog@fgos.pro']);
$author = $authorRow->fetch(PDO::FETCH_ASSOC);
if (!$author) {
    fwrite(STDERR, "System blog author (blog@fgos.pro) not found — run migration 164_add_blog_source.sql first.\n");
    exit(1);
}

$publicationObj = new Publication($db);
$id = $publicationObj->create([
    'user_id' => $author['id'],
    'title' => $title,
    'annotation' => $annotation,
    'content' => $content,
    'publication_type_id' => $type['id'],
    'slug' => $slug ?: $publicationObj->generateSlug($title),
    'source' => 'blog',
    'status' => 'published',
]);

echo "Created blog post #{$id}\n";
echo "URL: /blog/" . $publicationObj->getById($id)['slug'] . "/\n";

<?php
/** Создаёт компактный комплект новой редакционной статьи из шаблона. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

try {
    $options = getopt('', ['slug:', 'title:', 'help']);
    if (isset($options['help']) || !isset($options['slug'], $options['title'])) {
        echo "Использование: php scripts/editorial-init-article.php --slug=slug --title='Заголовок'\n";
        exit(isset($options['help']) ? 0 : 1);
    }
    $slug = (string) $options['slug'];
    $title = trim((string) $options['title']);
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug) || $title === '') {
        throw new InvalidArgumentException('Нужны корректный slug и непустой title.');
    }
    $root = dirname(__DIR__);
    $target = $root . '/editorial/articles/' . $slug;
    $template = $root . '/editorial/templates/article';
    if (file_exists($target)) {
        throw new RuntimeException('Каталог статьи уже существует: ' . $slug);
    }
    if (!mkdir($target, 0755, true)) {
        throw new RuntimeException('Не удалось создать каталог статьи.');
    }
    foreach (['brief.md', 'research.md', 'claims.md', 'sources.json', 'review.md'] as $file) {
        if (!copy($template . '/' . $file, $target . '/' . $file)) {
            throw new RuntimeException('Не удалось скопировать шаблон: ' . $file);
        }
    }
    file_put_contents($target . '/article.md', '# ' . $title . "\n\n");
    file_put_contents($target . '/article.html', "<p>Черновик статьи.</p>\n");
    $metadata = [
        'title' => $title,
        'slug' => $slug,
        'content-file' => 'article.html',
        'cover-image' => '/assets/images/blog/replace-cover.jpg',
        'annotation' => '',
        'type' => 'methodology',
        'tags' => '',
        'meta-title' => $title,
        'meta-description' => '',
        'noindex' => true,
    ];
    file_put_contents($target . '/metadata.json', json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n");
    echo "Создан черновик: editorial/articles/{$slug}/\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

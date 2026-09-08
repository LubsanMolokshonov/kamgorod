<?php
/** Проверка комплекта и атомарная публикация редакционной статьи. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

function blogPostPackage(array $input, string $root): array {
    if (isset($input['noindex']) && !is_bool($input['noindex'])) {
        throw new InvalidArgumentException('noindex должен быть boolean.');
    }
    $limits = ['title' => 500, 'slug' => 255, 'annotation' => 1000,
        'meta-title' => 255, 'meta-description' => 500];
    foreach ($limits as $key => $limit) {
        if (isset($input[$key]) && (!is_string($input[$key]) || mb_strlen($input[$key]) > $limit)) {
            throw new InvalidArgumentException("Недопустимое поле: {$key}");
        }
    }
    foreach (['title', 'content-file', 'cover-image', 'slug'] as $key) {
        if (!isset($input[$key]) || !is_string($input[$key]) || trim($input[$key]) === '') {
            throw new InvalidArgumentException("Обязательное поле: {$key}");
        }
    }
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $input['slug'])) {
        throw new InvalidArgumentException('Slug должен содержать латинские буквы, цифры и дефисы.');
    }
    $contentPath = $input['content-file'];
    if (!is_file($contentPath) || !is_readable($contentPath) || filesize($contentPath) > 2000000) {
        throw new InvalidArgumentException('HTML-файл отсутствует, недоступен или превышает 2 МБ.');
    }
    $content = file_get_contents($contentPath);
    if ($content === false || !mb_check_encoding($content, 'UTF-8') || trim(strip_tags($content)) === '') {
        throw new InvalidArgumentException('HTML должен содержать непустой текст UTF-8.');
    }
    // Это доверенный редакционный HTML, но ошибочные активные вставки не публикуем.
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $content . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $allowed = ['html', 'body', 'p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'strong', 'em',
        'a', 'table', 'caption', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'blockquote',
        'br', 'hr', 'figure', 'figcaption', 'code', 'pre', 'sup', 'sub'];
    foreach ($dom->getElementsByTagName('*') as $node) {
        if (!in_array($node->tagName, $allowed, true)) {
            throw new InvalidArgumentException('Недопустимый HTML-элемент: ' . $node->tagName);
        }
        foreach ($node->attributes as $attr) {
            if (!in_array($attr->name, ['id', 'href', 'title', 'scope', 'colspan', 'rowspan'], true)) {
                throw new InvalidArgumentException('Недопустимый HTML-атрибут: ' . $attr->name);
            }
            if ($attr->name === 'href' && !preg_match('~^(https?://[^\s]+|/(?!/)[^\s]*|#[a-zA-Z0-9_-]+)$~D', $attr->value)) {
                throw new InvalidArgumentException('Недопустимая ссылка в HTML.');
            }
        }
    }
    $cover = $input['cover-image'];
    if (!preg_match('~^/assets/images/blog/[a-z0-9/-]+\.(webp|jpg|png)$~D', $cover)) {
        throw new InvalidArgumentException('Обложка должна находиться в /assets/images/blog/ (webp, jpg, png).');
    }
    $coverPath = realpath($root . $cover);
    $coverRoot = realpath($root . '/assets/images/blog');
    if (!$coverPath || !$coverRoot || !str_starts_with($coverPath, $coverRoot . '/') || !is_file($coverPath)) {
        throw new InvalidArgumentException('Файл обложки отсутствует или находится за пределами каталога.');
    }
    $info = @getimagesize($coverPath);
    $expected = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'png' => 'image/png'];
    if (!$info || ($info['mime'] ?? '') !== $expected[pathinfo($coverPath, PATHINFO_EXTENSION)]
        || $info[0] < 600 || $info[1] < 300 || filesize($coverPath) > 2000000) {
        throw new InvalidArgumentException('Неверная обложка: нужен растровый файл от 600×300 до 2 МБ с верным расширением.');
    }
    $tags = $input['tags'] ?? '';
    if (!is_string($tags)) { throw new InvalidArgumentException('tags должен быть строкой slug через запятую.'); }
    $type = $input['type'] ?? 'article';
    if (!is_string($type) || !preg_match('/^[a-z-]+$/D', $type)) {
        throw new InvalidArgumentException('Неверный тип публикации.');
    }
    $package = [
        'title' => trim($input['title']), 'slug' => $input['slug'], 'content' => $content,
        'annotation' => $input['annotation'] ?? mb_substr(trim(strip_tags($content)), 0, 300),
        'meta_title' => $input['meta-title'] ?? trim($input['title']),
        'meta_description' => $input['meta-description'] ?? ($input['annotation'] ?? mb_substr(trim(strip_tags($content)), 0, 160)),
        'type' => $type, 'tags' => array_values(array_unique(array_filter(array_map('trim', explode(',', $tags))))),
        'noindex' => !empty($input['noindex']) ? 1 : 0,
        'cover_image_url' => $cover, 'cover_sha256' => hash_file('sha256', $coverPath),
    ];
    $package['sha256'] = hash('sha256', json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return $package;
}

function blogPostReferences(PDO $pdo, array $package): array {
    $q = $pdo->prepare('SELECT id FROM publications WHERE slug = ?');
    $q->execute([$package['slug']]);
    if ($q->fetchColumn() !== false) { throw new RuntimeException('Slug уже занят. Статья не изменена.'); }
    $q = $pdo->prepare('SELECT id FROM publication_types WHERE slug = ?');
    $q->execute([$package['type']]);
    $typeId = $q->fetchColumn();
    if (!$typeId) { throw new RuntimeException('Неизвестный тип публикации.'); }
    $q = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $q->execute(['blog@fgos.pro']);
    $authorId = $q->fetchColumn();
    if (!$authorId) { throw new RuntimeException('Автор blog@fgos.pro отсутствует.'); }
    $tagIds = [];
    foreach ($package['tags'] as $tag) {
        $q = $pdo->prepare('SELECT id FROM publication_tags WHERE slug = ?');
        $q->execute([$tag]);
        $id = $q->fetchColumn();
        if (!$id) { throw new RuntimeException('Неизвестный тег: ' . $tag); }
        $tagIds[] = $id;
    }
    // Проверяем наличие полей обложки до любого INSERT.
    $pdo->query('SELECT cover_image_url, cover_status FROM publications LIMIT 0');
    return ['user_id' => $authorId, 'publication_type_id' => $typeId, 'tag_ids' => $tagIds];
}

function blogPostPublish(PDO $pdo, array $package): int {
    $locked = false;
    try {
        // Один замок для всех запусков CLI, включая коллизию slug при параллельном старте.
        $q = $pdo->prepare('SELECT GET_LOCK(?, 10)');
        $q->execute(['fgos_editorial_blog_publish']);
        $locked = (int)$q->fetchColumn() === 1;
        if (!$locked) { throw new RuntimeException('Публикация занята другим процессом. Повторите позже.'); }
        $pdo->beginTransaction();
        $references = blogPostReferences($pdo, $package);
        $publication = new Publication($pdo);
        $id = $publication->create(array_merge($package, $references, ['source' => 'blog', 'status' => 'published']));
        $q = $pdo->prepare("UPDATE publications SET cover_image_url = ?, cover_status = 'done' WHERE id = ?");
        $q->execute([$package['cover_image_url'], $id]);
        $pdo->commit();
        return (int)$id;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    } finally {
        if ($locked) {
            $q = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $q->execute(['fgos_editorial_blog_publish']);
        }
    }
}

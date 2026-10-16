<?php
/** Проверка одобренного расписания и идемпотентный выпуск статей. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/blog-post.php';

function blogScheduleLoad(string $root, string $file): array {
    $root = realpath($root);
    $scheduleRoot = realpath($root . '/editorial/schedules');
    $file = realpath($file);
    if (!$scheduleRoot || !$file || !str_starts_with($file, $scheduleRoot . '/')) {
        throw new InvalidArgumentException('Расписание должно находиться в editorial/schedules.');
    }
    $data = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    if (($data['version'] ?? null) !== 1 || empty($data['entries']) || count($data['entries']) > 1000) {
        throw new InvalidArgumentException('Неверное расписание.');
    }
    $entries = []; $slugs = [];
    foreach ($data['entries'] as $entry) {
        $when = $entry['publish_at'] ?? '';
        if (!is_string($when) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $when)) {
            throw new InvalidArgumentException('Время должно быть ISO8601 с часовым поясом.');
        }
        $at = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $when);
        if (!$at || $at->format('Y-m-d\TH:i:sP') !== $when) {
            throw new InvalidArgumentException('Некорректная календарная дата.');
        }
        $manifest = $entry['manifest'] ?? '';
        if (!is_string($manifest) || !preg_match('~^editorial/articles/([a-z0-9-]+)/metadata\.json$~D', $manifest, $m)) {
            throw new InvalidArgumentException('Неверный путь manifest.');
        }
        $dir = realpath($root . '/editorial/articles/' . $m[1]);
        $articleRoot = realpath($root . '/editorial/articles');
        if (!$dir || !str_starts_with($dir, $articleRoot . '/')) {
            throw new InvalidArgumentException('Каталог статьи отсутствует.');
        }
        $input = json_decode(file_get_contents($root . '/' . $manifest), true, 512, JSON_THROW_ON_ERROR);
        $content = realpath($dir . '/' . ($input['content-file'] ?? ''));
        if (!$content || !str_starts_with($content, $dir . '/')) {
            throw new InvalidArgumentException('HTML должен находиться в каталоге статьи.');
        }
        $input['content-file'] = $content;
        $package = blogPostPackage($input, $root);
        if ($package['slug'] !== $m[1] || isset($slugs[$package['slug']])) {
            throw new InvalidArgumentException('Slug не совпадает или повторяется.');
        }
        $approved = $entry['approved_sha256'] ?? '';
        if (!is_string($approved) || !preg_match('/^[a-f0-9]{64}$/D', $approved) || !hash_equals($approved, $package['sha256'])) {
            throw new RuntimeException('Изменён согласованный комплект: ' . $package['slug']);
        }
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $package['content'], LIBXML_NONET);
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $images = [];
        foreach ($dom->getElementsByTagName('img') as $image) { $images[$image->getAttribute('src')] = true; }
        $approvedAssets = $entry['inline_assets_sha256'] ?? [];
        if (!is_array($approvedAssets) || count($approvedAssets) !== count($images)) {
            throw new RuntimeException('Неполный перечень одобренных иллюстраций.');
        }
        foreach ($images as $url => $_) {
            $path = realpath($root . $url);
            $hash = $approvedAssets[$url] ?? '';
            if (!$path || !str_starts_with($path, realpath($root . '/assets/images/blog') . '/')
                || !is_string($hash) || !hash_equals($hash, hash_file('sha256', $path))) {
                throw new RuntimeException('Изменена иллюстрация: ' . $url);
            }
        }
        $slugs[$package['slug']] = true;
        $entries[] = ['at' => $at, 'package' => $package];
    }
    usort($entries, static fn($a, $b) => $a['at'] <=> $b['at']);
    return $entries;
}

/** Чужой или изменённый slug никогда не перезаписывается. */
function blogScheduleExisting(PDO $pdo, array $package): ?int {
    $q = $pdo->prepare('SELECT p.*, pt.slug AS type_slug, u.email AS author_email
        FROM publications p LEFT JOIN publication_types pt ON pt.id = p.publication_type_id
        LEFT JOIN users u ON u.id = p.user_id WHERE p.slug = ?');
    $q->execute([$package['slug']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) { return null; }
    $expected = ['source' => 'blog', 'status' => 'published', 'cover_status' => 'done',
        'author_email' => 'blog@fgos.pro', 'type_slug' => $package['type']];
    foreach (['title', 'annotation', 'content', 'meta_title', 'meta_description', 'noindex', 'cover_image_url'] as $key) {
        $expected[$key] = $package[$key];
    }
    foreach ($expected as $key => $value) {
        if ((string)($row[$key] ?? '') !== (string)$value) {
            throw new RuntimeException('Конфликт существующего slug ' . $package['slug'] . ', поле ' . $key);
        }
    }
    $q = $pdo->prepare('SELECT t.slug FROM publication_tag_relations r
        JOIN publication_tags t ON t.id = r.tag_id WHERE r.publication_id = ?');
    $q->execute([$row['id']]);
    $tags = $q->fetchAll(PDO::FETCH_COLUMN); $expectedTags = $package['tags'];
    sort($tags); sort($expectedTags);
    if ($tags !== $expectedTags) { throw new RuntimeException('Конфликт тегов: ' . $package['slug']); }
    return (int)$row['id'];
}

/** Сначала весь preflight; затем запись только наступивших элементов. */
function blogScheduleProcess(PDO $pdo, array $entries, DateTimeImmutable $now, bool $publish, ?callable $publisher = null): array {
    $publisher ??= 'blogPostPublish';
    $existing = [];
    foreach ($entries as $i => $entry) {
        $existing[$i] = blogScheduleExisting($pdo, $entry['package']);
        if ($existing[$i] === null) { blogPostReferences($pdo, $entry['package']); }
        elseif ($entry['at'] > $now) { throw new RuntimeException('Будущая статья уже опубликована: ' . $entry['package']['slug']); }
    }
    $results = [];
    foreach ($entries as $i => $entry) {
        $id = $existing[$i];
        $status = $id !== null ? 'already_published' : ($entry['at'] > $now ? 'scheduled' : 'due');
        if ($status === 'due' && $publish) {
            $id = (int)$publisher($pdo, $entry['package']);
            if (blogScheduleExisting($pdo, $entry['package']) !== $id) { throw new RuntimeException('Не прошла проверка записи.'); }
            $status = 'published';
        }
        $results[] = ['slug' => $entry['package']['slug'], 'publish_at' => $entry['at']->format(DATE_ATOM), 'status' => $status, 'id' => $id];
    }
    return $results;
}

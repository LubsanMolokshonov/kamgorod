<?php
/** Безопасное обновление одной публикации из согласованной серии 21.09.2026. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

try {
    $write = false;
    $publicationId = null;
    $approvedHash = null;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') {
            $write = true;
        } elseif (str_starts_with($arg, '--id=')) {
            $value = substr($arg, strlen('--id='));
            if (!ctype_digit($value)) {
                throw new RuntimeException('--id должен быть положительным целым числом.');
            }
            $publicationId = (int) $value;
        } elseif (str_starts_with($arg, '--approved-sha256=')) {
            $approvedHash = substr($arg, strlen('--approved-sha256='));
        } else {
            throw new RuntimeException(
                'Допустимы только --id=<id>, --apply и --approved-sha256=<hash>.'
            );
        }
    }
    if (!$publicationId) {
        throw new RuntimeException('Укажите --id=<id> из согласованной серии.');
    }

    $root = dirname(__DIR__);
    $batch = json_decode(
        file_get_contents($root . '/editorial/batches/user-publications-20260921.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $target = null;
    foreach ($batch['articles'] ?? [] as $article) {
        if ((int) ($article['id'] ?? 0) === $publicationId) {
            $target = $article;
            break;
        }
    }
    if (!$target) {
        throw new RuntimeException('ID не входит в согласованную серию.');
    }

    $dir = $root . '/editorial/articles/' . $target['slug'];
    $revision = json_decode(
        file_get_contents($dir . '/revision-20260921.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    if ((int) ($revision['publication_id'] ?? 0) !== $publicationId
        || ($revision['slug'] ?? '') !== $target['slug']) {
        throw new RuntimeException('Ревизия не соответствует целевой публикации.');
    }
    $html = file_get_contents($dir . '/article.html');
    if ($html === false || !hash_equals($revision['new_html_sha256'], hash('sha256', $html))) {
        throw new RuntimeException('HTML изменился после подготовки ревизии.');
    }

    require_once $root . '/scripts/lib/blog-post.php';
    $manifest = json_decode(
        file_get_contents($dir . '/metadata.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $manifest['content-file'] = $dir . '/article.html';
    $package = blogPostPackage($manifest, $root);
    if (!hash_equals($target['approved_package_sha256'], $package['sha256'])) {
        throw new RuntimeException('Хеш комплекта не совпадает с согласованной серией.');
    }
    if ($write && (!$approvedHash || !hash_equals($package['sha256'], $approvedHash))) {
        throw new RuntimeException('Для записи нужен точный --approved-sha256 комплекта.');
    }

    require $root . '/config/database.php';
    $db->beginTransaction();
    $q = $db->prepare(
        'SELECT id, user_id, slug, source, status, title, annotation, content,
                content_original, publication_type_id, meta_title, meta_description,
                cover_image_url, cover_status, format_status, noindex, published_at,
                indexable_at, certificate_status, updated_at
           FROM publications
          WHERE id = ? AND slug = ?
          FOR UPDATE'
    );
    $q->execute([$publicationId, $target['slug']]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row
        || $row['source'] !== $target['source']
        || $row['status'] !== $target['status']) {
        throw new RuntimeException('Целевая опубликованная пользовательская статья не найдена.');
    }
    if ((int) $row['user_id'] !== (int) $target['user_id']) {
        throw new RuntimeException('Автор публикации изменился; обновление остановлено.');
    }
    if ($row['content'] === $package['content']
        && $row['title'] === $package['title']
        && $row['annotation'] === $package['annotation']
        && $row['meta_title'] === $package['meta_title']
        && $row['meta_description'] === $package['meta_description']
        && $row['cover_image_url'] === $package['cover_image_url']) {
        $db->rollBack();
        echo "Публикация {$publicationId} уже обновлена; запись не нужна.\n";
        exit(0);
    }
    if (!hash_equals($target['expected_content_sha256'], hash('sha256', (string) $row['content']))) {
        throw new RuntimeException('Содержимое production изменилось; обновление остановлено.');
    }

    $q = $db->prepare('SELECT id FROM publication_types WHERE slug = ? LIMIT 1');
    $q->execute([$package['type']]);
    $typeId = $q->fetchColumn();
    if (!$typeId) {
        throw new RuntimeException('Тип публикации из комплекта не найден.');
    }

    if (!$write) {
        $db->rollBack();
        echo "DRY-RUN OK: публикация {$publicationId}; исходный SHA256 совпал; комплект проверен.\n";
        echo 'Комплект SHA256: ' . $package['sha256'] . "\n";
        exit(0);
    }

    $backupDir = $root . '/editorial/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
        throw new RuntimeException('Не удалось создать каталог резервных копий.');
    }
    chmod($backupDir, 0700);
    $backup = $backupDir . '/publication-' . $publicationId . '-'
        . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    if (file_put_contents(
        $backup,
        json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        LOCK_EX
    ) === false) {
        throw new RuntimeException('Не удалось сохранить исходную публикацию.');
    }
    chmod($backup, 0600);

    $q = $db->prepare(
        "UPDATE publications
            SET title = ?, annotation = ?, content = ?,
                content_original = COALESCE(NULLIF(content_original, ''), ?),
                publication_type_id = ?, meta_title = ?, meta_description = ?,
                cover_image_url = ?, cover_status = 'done', format_status = 'done',
                noindex = ?
          WHERE id = ? AND slug = ?"
    );
    $q->execute([
        $package['title'],
        $package['annotation'],
        $package['content'],
        $row['content'],
        $typeId,
        $package['meta_title'],
        $package['meta_description'],
        $package['cover_image_url'],
        $package['noindex'],
        $publicationId,
        $target['slug'],
    ]);
    if ($q->rowCount() !== 1) {
        throw new RuntimeException('Ожидалось изменение ровно одной публикации.');
    }
    $db->commit();

    echo "Обновлена публикация {$publicationId}. Резервная копия: {$backup}\n";
    echo 'HTML SHA256: ' . hash('sha256', $package['content']) . "\n";
    echo 'Комплект SHA256: ' . $package['sha256'] . "\n";
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

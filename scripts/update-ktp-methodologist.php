<?php
/** Точечное обновление опубликованной статьи по правкам методиста от 09.09.2026. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

try {
    $write = in_array('--apply', $argv, true);
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg !== '--apply') { throw new RuntimeException('Допустим только --apply; по умолчанию dry-run.'); }
    }
    $root = dirname(__DIR__);
    $dir = $root . '/editorial/articles/ktp-shkolnomu-uchitelyu';
    $revision = json_decode(file_get_contents($dir . '/revision-20260909.json'), true, 512, JSON_THROW_ON_ERROR);
    $html = file_get_contents($dir . '/article.html');
    if (!hash_equals($revision['new_html_sha256'], hash('sha256', $html))) {
        throw new RuntimeException('HTML изменился после подготовки ревизии.');
    }
    require_once $root . '/scripts/lib/blog-post.php';
    $manifest = json_decode(file_get_contents($dir . '/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    $manifest['content-file'] = $dir . '/article.html';
    $package = blogPostPackage($manifest, $root);
    require $root . '/config/database.php';
    $db->beginTransaction();
    $q = $db->prepare('SELECT id, slug, source, status, content FROM publications WHERE id = ? AND slug = ? FOR UPDATE');
    $q->execute([$revision['publication_id'], $revision['slug']]);
    $row = $q->fetch();
    if (!$row || $row['source'] !== 'blog' || $row['status'] !== 'published') {
        throw new RuntimeException('Целевая опубликованная статья не найдена.');
    }
    if ($row['content'] === $package['content']) {
        $db->rollBack();
        echo "Статья уже обновлена; запись не нужна.\n";
        exit(0);
    }
    if (!hash_equals($revision['expected_content_sha256'], hash('sha256', $row['content']))) {
        throw new RuntimeException('Содержимое на сервере изменилось; обновление остановлено.');
    }
    if (!$write) {
        $db->rollBack();
        echo "DRY-RUN OK: статья 800; исходный SHA256 совпал; HTML проверен.\n";
        exit(0);
    }
    $backupDir = $root . '/editorial/backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0700, true)) {
        throw new RuntimeException('Не удалось создать каталог резервных копий.');
    }
    $backup = $backupDir . '/ktp-800-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.json';
    if (file_put_contents($backup, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить исходную статью.');
    }
    chmod($backup, 0600);
    $q = $db->prepare('UPDATE publications SET content = ? WHERE id = ? AND slug = ?');
    $q->execute([$package['content'], $revision['publication_id'], $revision['slug']]);
    if ($q->rowCount() !== 1) { throw new RuntimeException('Ожидалось изменение ровно одной статьи.'); }
    $db->commit();
    echo "Обновлена статья 800. Резервная копия: {$backup}\n";
    echo 'SHA256: ' . hash('sha256', $package['content']) . "\n";
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) { $db->rollBack(); }
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

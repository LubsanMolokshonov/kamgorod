<?php
/** Компактная проверка комплекта редакционной статьи без публикации. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

function editorialCheckFail(array &$errors, string $message): void {
    $errors[] = $message;
}

try {
    $options = getopt('', ['article:', 'help']);
    if (isset($options['help']) || !isset($options['article'])) {
        echo "Использование: php scripts/editorial-check.php --article=slug\n";
        exit(isset($options['help']) ? 0 : 1);
    }
    $slug = (string) $options['article'];
    if (!preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $slug)) {
        throw new InvalidArgumentException('Укажите slug статьи без пути.');
    }
    $root = dirname(__DIR__);
    $articleDir = $root . '/editorial/articles/' . $slug;
    $errors = [];
    $warnings = [];
    foreach (['brief.md', 'research.md', 'claims.md', 'sources.json', 'article.md', 'article.html', 'metadata.json', 'review.md'] as $file) {
        if (!is_file($articleDir . '/' . $file) || filesize($articleDir . '/' . $file) === 0) {
            editorialCheckFail($errors, 'Отсутствует или пуст: ' . $file);
        }
    }
    if ($errors !== []) {
        throw new RuntimeException(implode('; ', $errors));
    }

    $metadata = json_decode((string) file_get_contents($articleDir . '/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    if (($metadata['slug'] ?? null) !== $slug) {
        editorialCheckFail($errors, 'metadata.json содержит другой slug.');
    }
    $sourceData = json_decode((string) file_get_contents($articleDir . '/sources.json'), true, 512, JSON_THROW_ON_ERROR);
    $library = json_decode((string) file_get_contents($root . '/editorial/source-library/catalog.json'), true, 512, JSON_THROW_ON_ERROR);
    $librarySources = [];
    foreach ($library['sources'] ?? [] as $source) { $librarySources[$source['id']] = $source; }

    $sourceMode = 'legacy';
    if (isset($sourceData['sources'])) {
        $sourceMode = 'library';
        if (!is_array($sourceData['sources']) || $sourceData['sources'] === []) {
            editorialCheckFail($errors, 'В sources.json нет ссылок на карточки библиотеки.');
        }
        foreach ($sourceData['sources'] ?? [] as $reference) {
            $sourceId = $reference['library_id'] ?? '';
            $claimId = $reference['claim_id'] ?? '';
            if (!isset($librarySources[$sourceId])) {
                editorialCheckFail($errors, 'Неизвестная карточка библиотеки: ' . $sourceId);
                continue;
            }
            $claims = array_column($librarySources[$sourceId]['claims'] ?? [], null, 'id');
            if (!isset($claims[$claimId])) {
                editorialCheckFail($errors, 'Неизвестное утверждение ' . $claimId . ' в карточке ' . $sourceId);
            }
        }
    } elseif (!is_array($sourceData) || $sourceData === []) {
        editorialCheckFail($errors, 'sources.json должен содержать источники.');
    } else {
        $warnings[] = 'Старый формат sources.json: рекомендуется перейти на карточки библиотеки.';
    }

    $articleMd = (string) file_get_contents($articleDir . '/article.md');
    $articleHtml = (string) file_get_contents($articleDir . '/article.html');
    if (!preg_match('/^#\s+.+/m', $articleMd)) {
        editorialCheckFail($errors, 'article.md должен содержать H1 в Markdown.');
    }
    if (preg_match('~<h1\b~i', $articleHtml)) {
        editorialCheckFail($errors, 'article.html не должен содержать H1.');
    }
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $articleHtml . '</body></html>', LIBXML_NONET);
    libxml_clear_errors();
    $linkCount = 0;
    foreach ($dom->getElementsByTagName('a') as $link) {
        $linkCount++;
        if ($link->getAttribute('target') !== '_blank' || $link->getAttribute('rel') !== 'noopener noreferrer') {
            editorialCheckFail($errors, 'Ссылка #' . $linkCount . ' не содержит безопасного target/rel.');
        }
    }
    require_once $root . '/scripts/lib/blog-post.php';
    try {
        $input = $metadata;
        $input['content-file'] = $articleDir . '/' . ($metadata['content-file'] ?? '');
        blogPostPackage($input, $root);
    } catch (Throwable $e) {
        editorialCheckFail($errors, 'Пакет публикации: ' . $e->getMessage());
    }

    foreach ($warnings as $warning) { echo "ПРЕДУПРЕЖДЕНИЕ: {$warning}\n"; }
    foreach ($errors as $error) { fwrite(STDERR, "ОШИБКА: {$error}\n"); }
    printf("Статья: %s. Источники: %s. Ссылок: %d. Ошибок: %d.\n", $slug, $sourceMode, $linkCount, count($errors));
    exit($errors === [] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

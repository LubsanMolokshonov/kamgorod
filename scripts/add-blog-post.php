<?php
/**
 * Редакционная публикация блога. По умолчанию — проверка без записи в БД.
 * php scripts/add-blog-post.php --manifest=editorial/articles/<slug>/metadata.json
 * --validate-only: только проверка файлов, без подключения к БД.
 * --publish --approved-sha256=<hash>: публикация согласованной версии.
 * --update-existing: вместе с --publish обновляет существующую опубликованную blog-статью.
 * Вместо manifest допустимы --title= --slug= --content-file= --cover-image=
 * и необязательные --annotation= --type= --tags= --meta-title= --meta-description= --noindex.
 * Пути content-file в manifest считаются от его каталога, CLI — от текущего каталога.
 */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/lib/blog-post.php';

try {
    $options = [];
    $valueOptions = ['manifest', 'title', 'slug', 'content-file', 'cover-image', 'annotation',
        'type', 'tags', 'meta-title', 'meta-description', 'approved-sha256'];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/sD', $argument, $m)) {
            throw new InvalidArgumentException('Ожидался параметр --имя=значение.');
        }
        $key = $m[1];
        if (array_key_exists($key, $options)) { throw new InvalidArgumentException('Повтор параметра: ' . $key); }
        if (in_array($key, $valueOptions, true) && isset($m[2])) { $options[$key] = $m[2]; }
        elseif (in_array($key, ['publish', 'validate-only', 'noindex', 'update-existing'], true) && !isset($m[2])) { $options[$key] = true; }
        else { throw new InvalidArgumentException('Неизвестный параметр или неверный формат: ' . $key); }
    }
    if (isset($options['publish'], $options['validate-only'])) {
        throw new InvalidArgumentException('--publish несовместим с --validate-only.');
    }
    $input = $options;
    if (isset($options['manifest'])) {
        if (!is_file($options['manifest'])) { throw new InvalidArgumentException('Manifest отсутствует.'); }
        $input = json_decode(file_get_contents($options['manifest']), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($input)) { throw new InvalidArgumentException('Manifest должен быть JSON-объектом.'); }
        foreach ($options as $key => $value) {
            if (!in_array($key, ['manifest', 'publish', 'validate-only', 'approved-sha256', 'update-existing'], true)) {
                throw new InvalidArgumentException('Не смешивайте manifest и поля статьи.');
            }
        }
        if (isset($input['content-file']) && is_string($input['content-file']) && !str_starts_with($input['content-file'], '/')) {
            $input['content-file'] = dirname(realpath($options['manifest'])) . '/' . $input['content-file'];
        }
    }
    $package = blogPostPackage($input, dirname(__DIR__));
    echo "Комплект SHA256: {$package['sha256']}\nURL: /blog/{$package['slug']}/\n";
    if (!empty($options['validate-only'])) { echo "Файлы проверены. БД не проверялась; записи нет.\n"; exit(0); }
    if (!empty($options['publish']) && !hash_equals($package['sha256'], $options['approved-sha256'] ?? '')) {
        throw new RuntimeException('Для публикации нужен --approved-sha256 согласованного комплекта.');
    }
    require_once __DIR__ . '/../config/config.php';
    // В отличие от web-конфига, ошибка подключения должна давать ненулевой exit code.
    $db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    require_once __DIR__ . '/../classes/Database.php';
    require_once __DIR__ . '/../classes/Publication.php';
    if (empty($options['publish'])) {
        if (!empty($options['update-existing'])) { blogPostUpdateReferences($db, $package); }
        else { blogPostReferences($db, $package); }
        echo "Dry-run: файлы и БД проверены, ничего не опубликовано.\n";
        exit(0);
    }
    $updating = !empty($options['update-existing']);
    $id = $updating ? blogPostUpdate($db, $package) : blogPostPublish($db, $package);
    echo ($updating ? 'Обновлена' : 'Опубликована') . " статья #{$id}: /blog/{$package['slug']}/\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

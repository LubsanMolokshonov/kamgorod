#!/usr/bin/env php
<?php
/**
 * Сборка CSS-бандла для includes/header.php.
 *
 * Склеивает и минифицирует глобальные стили в assets/css/bundle.min.css.
 * Артефакт коммитится в git — на проде сборка не запускается (PHP работает
 * от www-data без прав записи в рабочую копию).
 *
 * Запуск: php scripts/build-assets.php
 * (локально без PHP: docker exec pedagogy_web php scripts/build-assets.php)
 *
 * После правок в любом из исходников (fonts/main/search/redesign/redesign-info)
 * пересобрать бандл и закоммитить. Если забыть — header.php сам увидит, что
 * бандл старее исходников, и откатится на отдельные <link> (медленнее, но не ломается).
 */

if (PHP_SAPI !== 'cli') { // scripts/ доступен через веб — наружу не отдаём
    http_response_code(404);
    exit;
}

require __DIR__ . '/../vendor/autoload.php';

use MatthiasMullie\Minify\CSS;

$root    = dirname(__DIR__);
$sources = ['fonts.css', 'main.css', 'search.css', 'redesign.css', 'redesign-info.css'];
$target  = $root . '/assets/css/bundle.min.css';

$minifier = new CSS();
foreach ($sources as $f) {
    $path = $root . '/assets/css/' . $f;
    if (!is_file($path)) {
        fwrite(STDERR, "ОШИБКА: не найден исходник $path\n");
        exit(1);
    }
    // Внешний @import в середине склейки браузер игнорирует по спецификации —
    // шрифты молча отвалятся. Ловим до того, как это уедет на прод.
    if (preg_match('#@import\s+url\(\s*[\'"]?https?://#i', file_get_contents($path))) {
        fwrite(STDERR, "ОШИБКА: внешний @import в $f — уберите его перед сборкой бандла\n");
        exit(1);
    }
    $minifier->add($path);
}

$minifier->minify($target);

$size = filesize($target);
printf("bundle.min.css: %d байт (gzip ~%d)\n", $size, strlen(gzencode(file_get_contents($target), 9)));

<?php
/**
 * Динамическая генерация OG-картинок
 * URL: /og-image/{type}/{slug}.jpg — OG-картинки для соцсетей (1200×630)
 * URL: /og-image/ad/{type}/{slug}.jpg — рекламные картинки для Яндекс Директ (600×600)
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OgImageGenerator.php';

// Допустимые типы контента и соответствующие классы
$allowedTypes = [
    'competition' => [
        'class' => 'Competition', 'title' => 'title', 'subtitle' => 'description',
        'price' => 'price', 'badge' => 'Всероссийский конкурс',
    ],
    'olympiad' => [
        'class' => 'Olympiad', 'title' => 'title', 'subtitle' => 'description',
        'price' => 'diploma_price', 'badge' => 'Всероссийская олимпиада',
    ],
    'webinar' => [
        'class' => 'Webinar', 'title' => 'title', 'subtitle' => 'short_description',
        'price' => 'certificate_price', 'badge' => 'Вебинар',
    ],
    'course' => [
        'class' => 'Course', 'title' => 'title', 'subtitle' => 'description',
        'price' => 'price', 'badge' => 'Курс',
    ],
    'publication' => [
        'class' => 'Publication', 'title' => 'title', 'subtitle' => 'annotation',
        'price' => null, 'badge' => 'Публикация',
    ],
];

$mode = $_GET['mode'] ?? 'og'; // 'og' или 'ad'
$type = $_GET['type'] ?? '';
$slug = $_GET['slug'] ?? '';

// Валидация
if (!isset($allowedTypes[$type])) {
    http_response_code(404);
    exit;
}

if (!preg_match('/^[a-z0-9-]+$/', $slug)) {
    http_response_code(400);
    exit;
}

$config = $allowedTypes[$type];
$generator = new OgImageGenerator();

// =============================================
// Рекламный режим (600×600, белый фон, цена)
// =============================================
if ($mode === 'ad') {
    $cacheKey = OgImageGenerator::buildAdCacheKey($type, $slug);
    $cacheDir = __DIR__ . '/../uploads/og-cache';
    $cachedFile = $cacheDir . '/ad-' . $cacheKey . '.jpg';

    if (file_exists($cachedFile) && (time() - filemtime($cachedFile)) < 604800) {
        $generator->serve($cachedFile);
    }

    // Загружаем данные из БД
    $classFile = __DIR__ . '/../classes/' . $config['class'] . '.php';
    require_once $classFile;

    $className = $config['class'];
    $obj = new $className($db);
    $item = $obj->getBySlug($slug);

    if (!$item) {
        http_response_code(404);
        exit;
    }

    // Курсы: шаблон с дипломом + динамическая аудитория
    if ($type === 'course') {
        $specializations = $obj->getSpecializations($item['id']);
        $audienceLabel = OgImageGenerator::buildAudienceLabel($specializations);
        $programType = $item['program_type'] ?? 'kpk';
        $filePath = $generator->getOrGenerateCourseAd($cacheKey, $audienceLabel, $programType);
        $generator->serve($filePath);
    }

    // Конкурсы: градиент + веер дипломов + аудитория
    if ($type === 'competition') {
        $audienceLabel = OgImageGenerator::buildCompetitionAudienceLabel(
            $item['target_participants'] ?? ''
        );
        $filePath = $generator->getOrGenerateContentAd($cacheKey, 'ВСЕРОССИЙСКИЙ КОНКУРС', $audienceLabel);
        $generator->serve($filePath);
    }

    // Олимпиады: градиент + веер дипломов + аудитория
    if ($type === 'olympiad') {
        $categories = $obj->getAudienceCategories($item['id']);
        $audienceLabel = OgImageGenerator::buildCategoryAudienceLabel($categories);
        $filePath = $generator->getOrGenerateContentAd($cacheKey, 'ВСЕРОССИЙСКАЯ ОЛИМПИАДА', $audienceLabel);
        $generator->serve($filePath);
    }

    // Вебинары: градиент + веер дипломов + аудитория
    if ($type === 'webinar') {
        $categories = $obj->getAudienceCategories($item['id']);
        $audienceLabel = OgImageGenerator::buildCategoryAudienceLabel($categories);
        $filePath = $generator->getOrGenerateContentAd($cacheKey, 'ВЕБИНАР', $audienceLabel);
        $generator->serve($filePath);
    }

    $title = $item[$config['title']] ?? '';
    $price = '';
    if ($config['price'] && !empty($item[$config['price']])) {
        $price = number_format((float)$item[$config['price']], 0, '', ' ');
    }
    $badge = $config['badge'] ?? '';

    $filePath = $generator->getOrGenerateAd($cacheKey, $type, $title, $price, $badge);
    $generator->serve($filePath);
}

// =============================================
// Стандартный OG-режим (1200×630, градиент)
// =============================================
$cacheKey = OgImageGenerator::buildCacheKey($type, $slug);

// Проверяем кэш — если есть, отдаём сразу без обращения к БД
$cacheDir = __DIR__ . '/../uploads/og-cache';
$cachedFile = $cacheDir . '/' . $cacheKey . '.jpg';

if (file_exists($cachedFile) && (time() - filemtime($cachedFile)) < 604800) {
    $generator->serve($cachedFile);
}

// Загружаем класс и получаем данные из БД
$classFile = __DIR__ . '/../classes/' . $config['class'] . '.php';
require_once $classFile;

$className = $config['class'];
$obj = new $className($db);
$item = $obj->getBySlug($slug);

if (!$item) {
    http_response_code(404);
    exit;
}

$title = $item[$config['title']] ?? '';
$subtitle = strip_tags($item[$config['subtitle']] ?? '');
$subtitle = mb_substr($subtitle, 0, 200);

// Генерация и отдача
$filePath = $generator->getOrGenerate($cacheKey, $type, $title, $subtitle);
$generator->serve($filePath);

<?php
/**
 * Массовая регенерация рекламных картинок для Яндекс Директ
 * Очищает кэш и генерирует новые картинки для всех конкурсов, олимпиад и вебинаров
 *
 * Запуск: php scripts/regenerate-ad-images.php
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OgImageGenerator.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../classes/Webinar.php';

$database = new Database($db);
$generator = new OgImageGenerator();
$cacheDir = __DIR__ . '/../uploads/og-cache';

$total = 0;
$errors = 0;

echo "=== Регенерация рекламных картинок ===\n\n";

// --- Конкурсы ---
echo "--- Конкурсы ---\n";
$competitions = $database->query("SELECT id, slug, target_participants FROM competitions WHERE is_active = 1");
foreach ($competitions as $item) {
    $cacheKey = OgImageGenerator::buildAdCacheKey('competition', $item['slug']);
    $cached = $cacheDir . '/ad-' . $cacheKey . '.jpg';
    if (file_exists($cached)) {
        unlink($cached);
    }

    $audienceLabel = OgImageGenerator::buildCompetitionAudienceLabel($item['target_participants'] ?? '');
    try {
        $generator->getOrGenerateContentAd($cacheKey, 'ВСЕРОССИЙСКИЙ КОНКУРС', $audienceLabel);
        echo "  OK: {$item['slug']} → {$audienceLabel}\n";
        $total++;
    } catch (Exception $e) {
        echo "  ОШИБКА: {$item['slug']} — {$e->getMessage()}\n";
        $errors++;
    }
}

// --- Олимпиады ---
echo "\n--- Олимпиады ---\n";
$olympiad = new Olympiad($db);
$olympiads = $database->query("SELECT id, slug FROM olympiads WHERE is_active = 1");
foreach ($olympiads as $item) {
    $cacheKey = OgImageGenerator::buildAdCacheKey('olympiad', $item['slug']);
    $cached = $cacheDir . '/ad-' . $cacheKey . '.jpg';
    if (file_exists($cached)) {
        unlink($cached);
    }

    $categories = $olympiad->getAudienceCategories($item['id']);
    $audienceLabel = OgImageGenerator::buildCategoryAudienceLabel($categories);
    try {
        $generator->getOrGenerateContentAd($cacheKey, 'ВСЕРОССИЙСКАЯ ОЛИМПИАДА', $audienceLabel);
        echo "  OK: {$item['slug']} → {$audienceLabel}\n";
        $total++;
    } catch (Exception $e) {
        echo "  ОШИБКА: {$item['slug']} — {$e->getMessage()}\n";
        $errors++;
    }
}

// --- Вебинары ---
echo "\n--- Вебинары ---\n";
$webinar = new Webinar($db);
$webinars = $database->query(
    "SELECT id, slug FROM webinars WHERE is_active = 1
     AND (status IN ('scheduled', 'live', 'videolecture') OR (status = 'completed' AND video_url IS NOT NULL))"
);
foreach ($webinars as $item) {
    $cacheKey = OgImageGenerator::buildAdCacheKey('webinar', $item['slug']);
    $cached = $cacheDir . '/ad-' . $cacheKey . '.jpg';
    if (file_exists($cached)) {
        unlink($cached);
    }

    $categories = $webinar->getAudienceCategories($item['id']);
    $audienceLabel = OgImageGenerator::buildCategoryAudienceLabel($categories);
    try {
        $generator->getOrGenerateContentAd($cacheKey, 'ВЕБИНАР', $audienceLabel);
        echo "  OK: {$item['slug']} → {$audienceLabel}\n";
        $total++;
    } catch (Exception $e) {
        echo "  ОШИБКА: {$item['slug']} — {$e->getMessage()}\n";
        $errors++;
    }
}

echo "\n=== Готово: {$total} картинок сгенерировано, {$errors} ошибок ===\n";

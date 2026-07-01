<?php
/**
 * Билдер JSON-LD-узла Product для страниц-листингов (списки курсов, олимпиад,
 * вебинаров, конкурсов, публикаций).
 *
 * Формат по ТЗ: лёгкий Product с aggregateRating на весь листинг.
 * Значения рейтинга подаются извне (гибрид: органический агрегат по review_stats
 * или синтетический фолбэк — см. страницы-листинги).
 */

require_once __DIR__ . '/rating-synthetic-helper.php';
require_once __DIR__ . '/../classes/Review.php';

if (!function_exists('buildListingSchema')) {
    /**
     * Собрать готовый узел Product для листинга (гибрид рейтинга «под ключ»).
     *
     * Рейтинг: если суммарно по типу набралось >= $organicThreshold реальных
     * отзывов — берём взвешенный органический агрегат; иначе детерминированный
     * синтетический фолбэк по ключу "listing:$section".
     *
     * @param mixed  $db          PDO-подключение (глобальный $db)
     * @param string $entityType  Тип для review_stats: course/competition/webinar/olympiad/publication
     * @param string $section     Слаг раздела (kursy/konkursy/...) — ключ синтетики
     * @param string $name        Заголовок страницы
     * @param string $description Описание страницы
     * @param string $image       Абсолютный URL картинки (обычно $ogImage)
     * @param string $brand       Бренд
     * @param int    $organicThreshold Порог реальных отзывов для органического агрегата
     * @return array
     */
    function buildListingSchema($db, string $entityType, string $section, string $name, string $description, string $image, string $brand, int $organicThreshold = 20): array {
        $agg = (new Review($db))->getTypeAggregate($entityType);
        if (($agg['count'] ?? 0) >= $organicThreshold) {
            $ratingValue = number_format((float)$agg['avg'], 1, '.', '');
            $ratingCount = (int)$agg['count'];
        } else {
            $key = 'listing:' . $section;
            $ratingValue = syntheticRatingValue($key);
            $ratingCount = syntheticReviewCount($key);
        }
        return buildListingProductJsonLd($name, $description, $image, $ratingValue, $ratingCount, $brand);
    }
}

if (!function_exists('buildListingProductJsonLd')) {
    /**
     * @param string $name        Название страницы (заголовок листинга)
     * @param string $description  Описание страницы
     * @param string $image        Абсолютный URL картинки (обычно $ogImage)
     * @param string $ratingValue  Значение рейтинга, например "4.8"
     * @param int    $ratingCount  Количество отзывов
     * @param string $brand        Бренд
     * @return array
     */
    function buildListingProductJsonLd(
        string $name,
        string $description,
        string $image,
        string $ratingValue,
        int $ratingCount,
        string $brand
    ): array {
        return [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'image' => $image,
            'name' => $name,
            'description' => $description,
            'brand' => $brand,
            'aggregateRating' => [
                '@type' => 'AggregateRating',
                'bestRating' => '5',
                'ratingCount' => $ratingCount,
                'ratingValue' => $ratingValue,
            ],
        ];
    }
}

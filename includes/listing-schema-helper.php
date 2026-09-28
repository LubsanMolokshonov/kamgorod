<?php
/**
 * Билдер JSON-LD-узла Product для страниц-листингов (списки курсов, олимпиад,
 * вебинаров, конкурсов, публикаций).
 *
 * AggregateRating добавляется только из сохранённых одобренных reviews.
 */

require_once __DIR__ . '/../classes/Review.php';

if (!function_exists('buildListingSchema')) {
    /**
     * Собрать Product для листинга. Пустой рейтинг не размечается.
     *
     * @param mixed  $db          PDO-подключение (глобальный $db)
     * @param string $entityType  Тип для review_stats: course/competition/webinar/olympiad/publication
     * @param string $section     Слаг раздела; оставлен для совместимости вызовов
     * @param string $name        Заголовок страницы
     * @param string $description Описание страницы
     * @param string $image       Абсолютный URL картинки (обычно $ogImage)
     * @param string $brand       Бренд
     * @param int    $organicThreshold Устаревший параметр; оставлен для совместимости
     * @return array
     */
    function buildListingSchema($db, string $entityType, string $section, string $name, string $description, string $image, string $brand, int $organicThreshold = 20): array {
        $agg = (new Review($db))->getTypeAggregate($entityType);
        $ratingValue = ($agg['count'] ?? 0) > 0
            ? number_format((float)$agg['avg'], 1, '.', '')
            : null;
        $ratingCount = (int)($agg['count'] ?? 0);
        return buildListingProductJsonLd($name, $description, $image, $ratingValue, $ratingCount, $brand);
    }
}

if (!function_exists('buildListingProductJsonLd')) {
    /**
     * @param string $name        Название страницы (заголовок листинга)
     * @param string $description  Описание страницы
     * @param string $image        Абсолютный URL картинки (обычно $ogImage)
     * @param string|null $ratingValue Значение рейтинга или null, если отзывов нет
     * @param int    $ratingCount  Количество отзывов
     * @param string $brand        Бренд
     * @return array
     */
    function buildListingProductJsonLd(
        string $name,
        string $description,
        string $image,
        ?string $ratingValue,
        int $ratingCount,
        string $brand
    ): array {
        $node = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'image' => $image,
            'name' => $name,
            'description' => $description,
            'brand' => $brand,
        ];
        if ($ratingValue !== null && $ratingCount > 0) {
            $node['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'bestRating' => '5',
                'ratingCount' => $ratingCount,
                'ratingValue' => $ratingValue,
            ];
        }
        return $node;
    }
}

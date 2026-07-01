<?php
/**
 * Детерминированная синтетика для микроразметки (Schema.org).
 *
 * Значения выводятся из строкового ключа ("$entityType:$id" или "listing:$section")
 * через crc32() — поэтому СТАБИЛЬНЫ между запросами навсегда. Это критично:
 * «плавающий» ratingValue/reviewCount Google трактует как манипуляцию и штрафует.
 *
 * Используется как ФОЛБЭК: реальный рейтинг из review_stats всегда приоритетнее
 * (гибрид — см. applyReviewSchema() и логику листингов).
 */

if (!function_exists('syntheticRatingValue')) {
    /**
     * Детерминированный рейтинг 4.5–5.0 с шагом 0.1.
     * @param string $key Стабильный ключ сущности
     * @return string Например "4.7"
     */
    function syntheticRatingValue(string $key): string {
        $steps = ['4.5', '4.6', '4.7', '4.8', '4.9', '5.0'];
        return $steps[crc32($key) % count($steps)];
    }
}

if (!function_exists('syntheticReviewCount')) {
    /**
     * Детерминированное количество отзывов 50–2000.
     * @param string $key Стабильный ключ сущности
     * @return int
     */
    function syntheticReviewCount(string $key): int {
        return 50 + (int)(crc32($key . '|count') % 1951); // 50..2000
    }
}

if (!function_exists('syntheticSku')) {
    /**
     * Детерминированный артикул: латиница+цифры, без пробелов, 5–20 символов.
     * md5-hex (0-9a-f) — валидный набор; берём 12 символов в верхнем регистре.
     * @param string $key Стабильный ключ сущности
     * @return string Например "A1B2C3D4E5F6"
     */
    function syntheticSku(string $key): string {
        return strtoupper(substr(md5($key), 0, 12));
    }
}

if (!function_exists('buildSyntheticAggregateRating')) {
    /**
     * Узел AggregateRating с синтетическими (но стабильными) значениями.
     * @param string $key Стабильный ключ сущности
     * @return array
     */
    function buildSyntheticAggregateRating(string $key): array {
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => syntheticRatingValue($key),
            'reviewCount' => syntheticReviewCount($key),
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }
}

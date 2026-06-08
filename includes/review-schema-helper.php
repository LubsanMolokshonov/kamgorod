<?php
/**
 * Хелперы микроразметки отзывов (Schema.org JSON-LD).
 *
 * buildAggregateRatingJsonLd() и buildReviewNodes() возвращают узлы, которые
 * страница-деталка МЁРДЖИТ в свой существующий главный JSON-LD-узел
 * (Course/Event/Quiz/Article/LearningResource) как свойства aggregateRating и review.
 *
 * Органика-онли: при count == 0 aggregateRating НЕ выводится (пустой рейтинг
 * Google игнорирует/штрафует).
 */

if (!function_exists('buildAggregateRatingJsonLd')) {
    /**
     * Узел AggregateRating или null, если отзывов нет.
     * @param float $avg Средняя оценка
     * @param int $count Количество одобренных отзывов
     * @return array|null
     */
    function buildAggregateRatingJsonLd($avg, $count): ?array {
        $count = (int)$count;
        $avg = (float)$avg;
        if ($count < 1 || $avg <= 0) {
            return null;
        }
        return [
            '@type' => 'AggregateRating',
            'ratingValue' => number_format($avg, 1, '.', ''),
            'reviewCount' => $count,
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }
}

if (!function_exists('buildReviewNodes')) {
    /**
     * Массив узлов Review для JSON-LD из списка одобренных отзывов.
     * Отзывы без текста пропускаются (в разметке Review без reviewBody малополезен).
     * @param array $reviews Строки из Review::getApproved()
     * @param int $limit Максимум узлов
     * @return array
     */
    function buildReviewNodes(array $reviews, int $limit = 10): array {
        // Убрать угловые скобки из пользовательского текста: JSON-LD выводится внутри
        // <script type="application/ld+json">, и строка вида </script> могла бы
        // разорвать тег (stored XSS). json_encode тут флаг JSON_HEX_TAG не гарантирует
        // на всех точках вывода, поэтому чистим на уровне данных.
        $strip = fn($v) => str_replace(['<', '>'], '', trim((string)$v));

        $nodes = [];
        foreach ($reviews as $r) {
            if ((int)$limit > 0 && count($nodes) >= $limit) {
                break;
            }
            $text = $strip($r['review_text'] ?? '');
            if ($text === '') {
                continue; // только оценка без текста — не выводим как Review
            }
            $node = [
                '@type' => 'Review',
                'author' => [
                    '@type' => 'Person',
                    'name' => $strip($r['author_name'] ?? '') ?: 'Пользователь',
                ],
                'reviewRating' => [
                    '@type' => 'Rating',
                    'ratingValue' => (string)(int)($r['rating'] ?? 5),
                    'bestRating' => '5',
                    'worstRating' => '1',
                ],
                'reviewBody' => $text,
            ];
            if (!empty($r['created_at'])) {
                $node['datePublished'] = date('Y-m-d', strtotime($r['created_at']));
            }
            $nodes[] = $node;
        }
        return $nodes;
    }
}

if (!function_exists('applyReviewSchema')) {
    /**
     * Навесить aggregateRating и review[] на существующий JSON-LD-узел продукта.
     * Возвращает изменённый узел (или исходный, если отзывов нет).
     *
     * @param array $node Главный JSON-LD-узел (Course/Event/Quiz/Article/...)
     * @param array $stats ['avg'=>float, 'count'=>int] из Review::getStats()
     * @param array $reviews Строки из Review::getApproved() (опционально)
     * @return array
     */
    function applyReviewSchema(array $node, array $stats, array $reviews = []): array {
        $agg = buildAggregateRatingJsonLd($stats['avg'] ?? 0, $stats['count'] ?? 0);
        if ($agg === null) {
            return $node; // нет одобренных отзывов — разметку не добавляем
        }
        $node['aggregateRating'] = $agg;
        $reviewNodes = buildReviewNodes($reviews);
        if (!empty($reviewNodes)) {
            $node['review'] = $reviewNodes;
        }
        return $node;
    }
}

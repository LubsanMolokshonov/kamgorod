<?php
/**
 * Общий гейт публичности продукта для отзывов.
 * Используется при приёме отзыва (ajax/submit-review.php) и при автопубликации
 * сидовых отзывов (cron/publish-seeded-reviews.php) — чтобы не ставить отзыв
 * на удалённое/скрытое/черновое мероприятие.
 */

require_once __DIR__ . '/../classes/Database.php';

if (!function_exists('reviewEntityIsPublic')) {
    /**
     * Существует ли продукт и доступен ли он публично.
     * Таблица и условие выбираются из фиксированного whitelist'а — инъекция типа невозможна.
     */
    function reviewEntityIsPublic($pdo, string $entityType, int $entityId): bool {
        // [таблица, доп. условие публичности]
        $map = [
            'competition' => ['competitions', ''],
            'olympiad'    => ['olympiads', " AND is_active = 1"],
            'webinar'     => ['webinars', " AND status <> 'draft'"],
            'course'      => ['courses', " AND is_active = 1"],
            'publication' => ['publications', " AND status = 'published'"],
            'material'    => ['materials', " AND status = 'published'"],
        ];
        if (!isset($map[$entityType])) {
            return false;
        }
        [$table, $cond] = $map[$entityType];
        $row = (new Database($pdo))->queryOne(
            "SELECT id FROM {$table} WHERE id = ?{$cond} LIMIT 1",
            [$entityId]
        );
        return (bool)$row;
    }
}

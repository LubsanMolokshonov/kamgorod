-- 146_create_review_seed_queue.sql
-- Очередь предсгенерированных отзывов для постепенной автопубликации.
-- Генератор (scripts/seed-reviews.php) наполняет очередь один раз, cron
-- (cron/publish-seeded-reviews.php) пару раз в день переносит «дозревшие»
-- (scheduled_at <= NOW()) строки в таблицу reviews со status='approved'.
-- Дрип по разным мероприятиям маскирует наполнение от антиспама Google.

CREATE TABLE IF NOT EXISTS review_seed_queue (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('competition','olympiad','webinar','course','publication','material') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    author_name VARCHAR(120) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NULL,
    scheduled_at DATETIME NOT NULL,
    published_review_id INT UNSIGNED NULL,      -- NULL = ещё в очереди; иначе id строки в reviews
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_due (published_review_id, scheduled_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

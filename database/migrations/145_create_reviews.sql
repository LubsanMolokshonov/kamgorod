-- 145: Универсальные отзывы (звёзды + текст) для всех продуктов + кэш агрегатов.
-- Полиморфная таблица reviews (entity_type разделяет продукт), кэш в review_stats.
-- FK на 6 разных таблиц при полиморфизме невозможен — целостность держим в коде
-- (проверка существования продукта в ajax/submit-review.php).
-- Перенос существующего рейтинга публикаций (publication_ratings) в новую таблицу.

SET NAMES utf8mb4;

-- Таблица отзывов. Дедуп одного отзыва на сущность — по cookie-токену браузера.

CREATE TABLE IF NOT EXISTS reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('competition','olympiad','webinar','course','publication','material') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    author_name VARCHAR(120) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    review_text TEXT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    moderation_reason VARCHAR(255) NULL,
    vote_token VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    moderated_at TIMESTAMP NULL,
    UNIQUE KEY uniq_entity_token (entity_type, entity_id, vote_token),
    INDEX idx_entity_status (entity_type, entity_id, status),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш агрегатов (одна строка на сущность). Считается только по status='approved'.
-- Единый источник правды для микроразметки aggregateRating и виджета.

CREATE TABLE IF NOT EXISTS review_stats (
    entity_type ENUM('competition','olympiad','webinar','course','publication','material') NOT NULL,
    entity_id INT UNSIGNED NOT NULL,
    rating_avg DECIMAL(2,1) NOT NULL DEFAULT 0,
    rating_count INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Перенос старых оценок публикаций в reviews (только звёзды, без текста, одобрены).
-- INSERT IGNORE — повторный прогон миграции не задвоит строки (uniq_entity_token).
-- Выполняем только если таблица publication_ratings существует.

SET @has_pr = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publication_ratings');
SET @q = IF(@has_pr > 0,
    'INSERT IGNORE INTO reviews (entity_type, entity_id, author_name, rating, review_text, status, vote_token, ip_address, created_at, moderated_at)
     SELECT ''publication'', pr.publication_id, ''Читатель'', pr.rating, NULL, ''approved'', pr.vote_token, pr.ip_address, pr.created_at, pr.created_at
     FROM publication_ratings pr',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Заполнить review_stats для публикаций из перенесённых данных.

INSERT INTO review_stats (entity_type, entity_id, rating_avg, rating_count)
SELECT 'publication', entity_id, ROUND(AVG(rating), 1), COUNT(*)
FROM reviews
WHERE entity_type = 'publication' AND status = 'approved'
GROUP BY entity_id
ON DUPLICATE KEY UPDATE
    rating_avg = VALUES(rating_avg),
    rating_count = VALUES(rating_count);

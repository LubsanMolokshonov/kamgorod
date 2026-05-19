-- 116: Рейтинг публикаций (5-звёздочное голосование) + поля профиля автора
-- Таблица голосов, кэш-колонки рейтинга в publications, аватар/соцсети в users.

SET NAMES utf8mb4;

-- Таблица голосов рейтинга. Дедупликация — по cookie-токену браузера (vote_token).

CREATE TABLE IF NOT EXISTS publication_ratings (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    publication_id INT UNSIGNED NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    vote_token VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_pub_token (publication_id, vote_token),
    INDEX idx_publication (publication_id),
    FOREIGN KEY (publication_id) REFERENCES publications(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Кэш-колонки рейтинга в publications (пересчитываются после каждого голоса).

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publications' AND COLUMN_NAME = 'rating_avg');
SET @q = IF(@c = 0, 'ALTER TABLE publications ADD COLUMN rating_avg DECIMAL(2,1) NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'publications' AND COLUMN_NAME = 'rating_count');
SET @q = IF(@c = 0, 'ALTER TABLE publications ADD COLUMN rating_count INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Поля профиля автора в users. Биография — существующее author_bio.
-- Если author_bio ещё не существует (расширение users из миграции 016 не
-- накатывалось) — добавляем первым, чтобы AFTER author_bio ниже сработал.

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'author_bio');
SET @q = IF(@c = 0, 'ALTER TABLE users ADD COLUMN author_bio TEXT NULL AFTER profession', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'avatar_path');
SET @q = IF(@c = 0, 'ALTER TABLE users ADD COLUMN avatar_path VARCHAR(255) NULL AFTER author_bio', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'social_vk');
SET @q = IF(@c = 0, 'ALTER TABLE users ADD COLUMN social_vk VARCHAR(255) NULL AFTER avatar_path', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'social_telegram');
SET @q = IF(@c = 0, 'ALTER TABLE users ADD COLUMN social_telegram VARCHAR(255) NULL AFTER social_vk', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 183: Уникальный контент детальных страниц олимпиад и прозрачная маркировка ИИ-отзывов.
-- Идемпотентно: таблица создаётся через IF NOT EXISTS, колонки — через information_schema.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS olympiad_page_content (
    olympiad_id INT UNSIGNED NOT NULL,
    content_version SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    hero_text TEXT NOT NULL,
    about_html MEDIUMTEXT NOT NULL,
    faq_json JSON NOT NULL,
    source_hash CHAR(64) NOT NULL,
    generated_at DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (olympiad_id),
    KEY idx_olympiad_page_content_version (content_version),
    KEY idx_olympiad_page_content_source_hash (source_hash),
    CONSTRAINT fk_olympiad_page_content_olympiad
        FOREIGN KEY (olympiad_id) REFERENCES olympiads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'author_role');
SET @q = IF(@c = 0,
    'ALTER TABLE reviews ADD COLUMN author_role VARCHAR(160) NULL AFTER author_name',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND COLUMN_NAME = 'content_source');
SET @q = IF(@c = 0,
    'ALTER TABLE reviews ADD COLUMN content_source ENUM(''user'',''ai_example'') NOT NULL DEFAULT ''user'' AFTER review_text',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review_seed_queue' AND COLUMN_NAME = 'author_role');
SET @q = IF(@c = 0,
    'ALTER TABLE review_seed_queue ADD COLUMN author_role VARCHAR(160) NULL AFTER author_name',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'review_seed_queue' AND COLUMN_NAME = 'content_source');
SET @q = IF(@c = 0,
    'ALTER TABLE review_seed_queue ADD COLUMN content_source ENUM(''user'',''ai_example'') NOT NULL DEFAULT ''ai_example'' AFTER review_text',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Все прежние сидовые отзывы и вся специализированная seed-очередь являются ИИ-примерами.
UPDATE reviews
SET content_source = 'ai_example'
WHERE moderation_reason = 'seed' OR LEFT(vote_token, 5) = 'seed_';

UPDATE reviews
SET author_role = CASE entity_type
    WHEN 'olympiad' THEN 'Педагог'
    WHEN 'competition' THEN 'Учитель'
    WHEN 'course' THEN 'Слушатель курса'
    WHEN 'webinar' THEN 'Участник вебинара'
    WHEN 'publication' THEN 'Автор методических материалов'
    ELSE 'Педагог'
END
WHERE content_source = 'ai_example' AND (author_role IS NULL OR author_role = '');

UPDATE review_seed_queue
SET content_source = 'ai_example',
    author_role = CASE entity_type
        WHEN 'olympiad' THEN 'Педагог'
        WHEN 'competition' THEN 'Учитель'
        WHEN 'course' THEN 'Слушатель курса'
        WHEN 'webinar' THEN 'Участник вебинара'
        WHEN 'publication' THEN 'Автор методических материалов'
        ELSE 'Педагог'
    END
WHERE author_role IS NULL OR author_role = '';

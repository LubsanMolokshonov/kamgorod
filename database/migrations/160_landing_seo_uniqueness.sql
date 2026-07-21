-- 160: SEO-уникализация посадочных страниц (курсы/конкурсы/олимпиады).
--   1) name_dative у специализаций — для корректного H1 «Курсы ... по математике».
--   2) landing_seo_content — уникальный LSI-текст на каждую посадочную (ключ = canonical-путь).
--   3) landing_reviews — витрина отзывов на посадочной (12 шт), не связана с формой приёма.
-- Идемпотентно: ADD COLUMN через information_schema, CREATE TABLE IF NOT EXISTS.

SET NAMES utf8mb4;

-- 1. Дательный падеж названия специализации (для H1 «по <спец>»).
--    ADD COLUMN не идемпотентен в MySQL — оборачиваем в проверку information_schema.
SET @has_col = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audience_specializations'
      AND COLUMN_NAME = 'name_dative');
SET @q = IF(@has_col = 0,
    'ALTER TABLE audience_specializations ADD COLUMN name_dative VARCHAR(190) NULL AFTER name',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- 2. Уникальный SEO-текст посадочной. page_key = canonical-путь без домена и хвостового слэша
--    (напр. 'kursy/perepodgotovka/matematika').
CREATE TABLE IF NOT EXISTS landing_seo_content (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(190) NOT NULL,
    page_type ENUM('course','competition','olympiad') NOT NULL,
    seo_html TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_page_key (page_key),
    INDEX idx_page_type (page_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Витрина отзывов посадочной (сид-контент, не пользовательский приём).
--    Аватар не храним — инициалы и цвет вычисляются из author_name при рендере.
CREATE TABLE IF NOT EXISTS landing_reviews (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_key VARCHAR(190) NOT NULL,
    author_name VARCHAR(120) NOT NULL,
    rating TINYINT UNSIGNED NOT NULL DEFAULT 5,
    review_text TEXT NOT NULL,
    review_date DATE NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_page_key (page_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

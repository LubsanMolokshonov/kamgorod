-- Миграция 132: добивка колонок из 131 для окружений, где 131 частично упала
-- (MySQL 8 не понял ADD COLUMN IF NOT EXISTS). Идемпотентна.
-- Дата: 2026-05-29

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- materials.ai_output_json — сырой JSON ответа ИИ для рендера DOCX/PPTX на лету
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'materials' AND COLUMN_NAME = 'ai_output_json');
SET @q = IF(@c = 0, 'ALTER TABLE materials ADD COLUMN ai_output_json JSON NULL AFTER ai_params_json', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- material_types.needs_cover — нужна ли обложка (учительские материалы — нет)
SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'material_types' AND COLUMN_NAME = 'needs_cover');
SET @q = IF(@c = 0, 'ALTER TABLE material_types ADD COLUMN needs_cover TINYINT(1) NOT NULL DEFAULT 1 AFTER ai_model_key', 'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Учительские материалы — обложку не генерим (экономия токенов YandexART)
UPDATE material_types SET needs_cover = 0
    WHERE slug IN ('tehkarta-uroka', 'konspekt-uroka', 'test-kontrolnaya', 'ktp-fragment', 'klassnyy-chas');
UPDATE material_types SET needs_cover = 1
    WHERE slug IN ('rabochiy-list', 'prezentatsiya');

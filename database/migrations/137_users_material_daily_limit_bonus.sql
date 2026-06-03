-- Миграция 137: users.material_daily_limit_bonus — персональная прибавка к
-- суточному лимиту бесплатных превью-генераций материалов.
-- Базовый лимит для залогиненного — 10/сутки (см. materialPreviewRateLimit).
-- Кнопка «Увеличить лимит» в сообщении о достижении лимита прибавляет к этому
-- полю 300000 — фактически снимает ограничение для пользователя. Идемпотентна.
-- Дата: 2026-06-03

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'users'
            AND COLUMN_NAME = 'material_daily_limit_bonus');
SET @q = IF(@c = 0,
    'ALTER TABLE users ADD COLUMN material_daily_limit_bonus INT NOT NULL DEFAULT 0',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

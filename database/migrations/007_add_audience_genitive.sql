-- Migration: Add target_participants_genitive to audience_types
-- Created: 2026-01-16
-- Description: Добавляет поле для родительного падежа целевой аудитории в таблицу audience_types

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Добавить поле target_participants_genitive в таблицу audience_types
ALTER TABLE audience_types
ADD COLUMN target_participants_genitive TEXT COMMENT 'Целевая аудитория в родительном падеже (для заголовков)' AFTER description;

-- Заполнить данные для существующих типов аудитории
UPDATE audience_types
SET target_participants_genitive = CASE
    WHEN slug = 'dou' THEN 'воспитателей и педагогов дошкольного образования'
    WHEN slug = 'nachalnaya-shkola' THEN 'учителей начальных классов'
    WHEN slug = 'srednyaya-starshaya-shkola' THEN 'учителей предметников средней и старшей школы'
    WHEN slug = 'spo' THEN 'преподавателей колледжей и техникумов'
    ELSE LOWER(name)
END
WHERE target_participants_genitive IS NULL OR target_participants_genitive = '';

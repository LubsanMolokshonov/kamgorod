-- Migration: Add SEO Fields to Competitions
-- Created: 2026-01-16
-- Description: Добавление полей для SEO-оптимизации: цели, задачи, расширенное описание

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Добавляем новые поля для SEO
ALTER TABLE competitions
ADD COLUMN goals TEXT COMMENT 'Цели конкурса (для SEO)' AFTER description,
ADD COLUMN objectives TEXT COMMENT 'Задачи конкурса (для SEO)' AFTER goals,
ADD COLUMN seo_description TEXT COMMENT 'Расширенное SEO описание' AFTER objectives;

-- Добавляем индекс для поиска
ALTER TABLE competitions
ADD FULLTEXT INDEX idx_seo_content (description, goals, objectives, seo_description);

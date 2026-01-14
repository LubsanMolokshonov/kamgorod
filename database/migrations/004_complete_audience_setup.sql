-- Complete Audience Setup Migration
-- Объединенная миграция для настройки всей системы аудиторий и конкурсов
-- Created: 2026-01-14

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ========================================
-- ВНИМАНИЕ: Этот файл уже применен!
-- Используйте его только для развертывания на новом сервере
-- ========================================

-- Этот файл объединяет миграции:
-- - 002_add_audience_segmentation.sql
-- - 002_seed_audience_data.sql
-- - 003_create_audience_competitions.sql
-- - fix_competition_specializations.sql

-- Для повторного применения на существующей БД выполните:
-- 1. database/migrations/002_add_audience_segmentation.sql (создание таблиц)
-- 2. database/migrations/002_seed_audience_data.sql (данные аудиторий)
-- 3. database/migrations/003_create_audience_competitions.sql (конкурсы)
-- 4. database/fix_competition_specializations.sql (исправление связей)

SELECT 'Migration 004: Complete Audience Setup - Already Applied' as status;
SELECT 'Use individual migration files for re-deployment' as note;

SET FOREIGN_KEY_CHECKS = 1;

-- Миграция 126: AI-обложка для опубликованных статей журнала.
-- Дата: 2026-05-28
--
-- Цель: каждая статья выглядит законченной — релевантная иллюстрация по теме,
-- генерируется в фоне через cron (cron/process-publication-images.php) с помощью
-- YandexArtService. Бесплатно, на стороне системы (токены ФОП у автора не списываются).
--
-- cover_status='pending' по умолчанию — существующие опубликованные статьи попадут
-- в очередь бэкфилла, cron перебирает их батчами (троттлинг расходов Yandex Cloud).
--
-- Идемпотентность обеспечивается трекингом миграций (таблица migrations), не IF NOT EXISTS:
-- MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE publications
    ADD COLUMN cover_image_url VARCHAR(500) NULL AFTER content,
    ADD COLUMN cover_status ENUM('pending', 'done', 'failed', 'skipped') NOT NULL DEFAULT 'pending' AFTER cover_image_url,
    ADD INDEX idx_cover_queue (status, cover_status);

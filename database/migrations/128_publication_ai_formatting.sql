-- Миграция 128: AI-оформление текста загруженных вручную публикаций журнала.
-- Дата: 2026-05-28
--
-- Цель: публикации, которые педагоги загружают файлом (source='upload'), приходят с
-- «плоским» HTML без структуры (особенно из PDF — сплошные <p>). Оглавление
-- (buildArticleToc) и аккуратная типографика не работают. Фоновый cron
-- (cron/process-publication-formatting.php) прогоняет такой контент через YandexGPT,
-- который расставляет смысловые заголовки <h2>/<h3>, абзацы и списки, НЕ меняя слова
-- автора. Сгенерированных нейросетью статей (source='generator') это не касается —
-- у них структура уже есть.
--
-- content_original хранит исходный HTML до первого оформления: позволяет откатиться
-- и повторно прогнать форматирование, не теряя оригинал автора.
--
-- format_status='pending' по умолчанию — существующие загруженные публикации попадут
-- в очередь бэкфилла, cron перебирает их батчами (троттлинг расходов Yandex Cloud).
--
-- Идемпотентность обеспечивается трекингом миграций (таблица migrations), не IF NOT EXISTS:
-- MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS.

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

ALTER TABLE publications
    ADD COLUMN content_original LONGTEXT NULL AFTER content,
    ADD COLUMN format_status ENUM('pending', 'done', 'failed', 'skipped') NOT NULL DEFAULT 'pending' AFTER content_original,
    ADD INDEX idx_format_queue (source, status, format_status);

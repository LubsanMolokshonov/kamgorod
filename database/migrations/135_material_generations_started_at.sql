-- Миграция 135: material_generations.started_at — момент перехода задачи в 'running'.
-- Нужен для recovery зависших async-задач: считать «зависшей» по времени СТАРТА
-- обработки, а не по created_at (иначе задача, долго простоявшая в очереди pending
-- под нагрузкой, ложно опознаётся как зависшая сразу при старте). Идемпотентна.
-- Дата: 2026-06-01

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @c = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'material_generations'
            AND COLUMN_NAME = 'started_at');
SET @q = IF(@c = 0,
    'ALTER TABLE material_generations ADD COLUMN started_at TIMESTAMP NULL DEFAULT NULL AFTER status',
    'SELECT 1');
PREPARE stmt FROM @q;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

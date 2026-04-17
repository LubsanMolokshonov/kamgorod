-- Миграция 072: Обновление стадий Bitrix24 для воронки курсов
-- Убраны этапы 24ч/2д/3д из воронки Битрикс.
-- Добавлен этап «Перевод на менеджера» (90 мин) — только перевод стадии, без письма.
-- При переходе в ЦДО дальше «Подготовка документов» — деактивация email-цепочки.

-- 1. Добавить колонку bitrix_only (touchpoint без отправки email, только перевод стадии)
ALTER TABLE course_email_touchpoints
    ADD COLUMN bitrix_only TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Только перевод стадии Bitrix24, без отправки email'
    AFTER bitrix_stage_id;

-- 2. Убрать привязку к Bitrix-стадиям у touchpoints 24ч, 2д, 3д (письма остаются)
UPDATE course_email_touchpoints SET bitrix_stage_id = NULL WHERE code IN ('course_enroll_24h', 'course_enroll_2d', 'course_enroll_3d');

-- 3. Добавить touchpoint «Перевод на менеджера» — 90 минут, только Bitrix
INSERT INTO course_email_touchpoints (code, name, description, delay_minutes, email_subject, email_template, bitrix_stage_id, bitrix_only, is_active, display_order) VALUES
('course_enroll_90min_manager', 'Перевод на менеджера', 'Через 1,5 часа — перевод сделки на менеджера в ЦДО', 90, '', '', 'C108:UC_DLXNLQ', 1, 1, 4);

-- 4. Сдвинуть display_order для touchpoints после 1 часа
UPDATE course_email_touchpoints SET display_order = 5 WHERE code = 'course_enroll_24h';
UPDATE course_email_touchpoints SET display_order = 6 WHERE code = 'course_enroll_2d';
UPDATE course_email_touchpoints SET display_order = 7 WHERE code = 'course_enroll_3d';

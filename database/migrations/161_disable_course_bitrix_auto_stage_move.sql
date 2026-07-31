-- Миграция 161: отключение автоперевода сделки Bitrix24 на "менеджера" по времени
-- Раньше touchpoint course_enroll_5min_manager (5 минут после заявки, bitrix_only=1)
-- сам переводил сделку в C108:UC_DLXNLQ. Теперь переводы между этапами делают
-- только менеджеры вручную в Bitrix24 — bitrix_only остаётся 1 (email по этому
-- touchpoint не предусмотрен), просто убираем целевую стадию.

UPDATE course_email_touchpoints
SET bitrix_stage_id = NULL
WHERE code = 'course_enroll_5min_manager';

-- Миграция 073: Упрощение воронки Bitrix24 для курсов
-- Убраны промежуточные переводы стадий на 15 мин (UC_HWWIFQ) и 1 ч (UC_1YOFLO).
-- Перевод на менеджера (UC_DLXNLQ) теперь срабатывает на 5-й минуте вместо 90-й.
-- Email-цепочка (welcome / 15 мин / 1 ч / 24 ч / 2 д / 3 д) не меняется.

-- 1. Убрать привязку к Bitrix-стадиям у touchpoints 15 мин и 1 ч (письма остаются)
UPDATE course_email_touchpoints
SET bitrix_stage_id = NULL
WHERE code IN ('course_enroll_15min', 'course_enroll_1h');

-- 2. Перенастроить touchpoint «Перевод на менеджера» с 90 мин на 5 мин.
-- Обновляем существующую запись (не удаляем), чтобы не ломать ссылки в course_email_log.
UPDATE course_email_touchpoints
SET code = 'course_enroll_5min_manager',
    name = 'Перевод на менеджера',
    description = 'Через 5 минут после создания заявки — перевод сделки на менеджера, если не оплачено',
    delay_minutes = 5,
    display_order = 2
WHERE code = 'course_enroll_90min_manager';

-- 3. Пересдвинуть display_order: welcome(1), 5min_manager(2), 15min(3), 1h(4), 24h(5), 2d(6), 3d(7)
UPDATE course_email_touchpoints SET display_order = 3 WHERE code = 'course_enroll_15min';
UPDATE course_email_touchpoints SET display_order = 4 WHERE code = 'course_enroll_1h';

-- 4. Пере-расписать ещё не отправленные записи в course_email_log,
-- чтобы старые pending-задачи на 90 мин не висели лишнее время.
UPDATE course_email_log cel
JOIN course_email_touchpoints tp ON tp.id = cel.touchpoint_id
JOIN course_enrollments ce ON ce.id = cel.enrollment_id
SET cel.scheduled_at = DATE_ADD(ce.created_at, INTERVAL 5 MINUTE)
WHERE tp.code = 'course_enroll_5min_manager'
  AND cel.status = 'pending';

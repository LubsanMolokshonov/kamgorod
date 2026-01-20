-- Migration: Add target_participants_genitive field
-- Date: 2026-01-16
-- Description: Добавляет поле для хранения целевой аудитории в родительном падеже

-- Добавить новое поле
ALTER TABLE competitions
ADD COLUMN target_participants_genitive TEXT AFTER target_participants;

-- Заполнить данные для существующих конкурсов
-- Примеры для типичных конкурсов (нужно будет адаптировать под ваши данные)
UPDATE competitions
SET target_participants_genitive = CASE
    WHEN target_participants LIKE '%Воспитатели%' THEN 'воспитателей дошкольных образовательных учреждений'
    WHEN target_participants LIKE '%Учителя%' THEN 'учителей общеобразовательных учреждений'
    WHEN target_participants LIKE '%Педагоги%' THEN 'педагогов образовательных учреждений'
    WHEN target_participants LIKE '%Методисты%' THEN 'методистов образовательных учреждений'
    WHEN target_participants LIKE '%Студенты%' THEN 'студентов педагогических учебных заведений'
    ELSE target_participants
END
WHERE target_participants_genitive IS NULL;

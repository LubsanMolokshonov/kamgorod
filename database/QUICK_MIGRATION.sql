-- БЫСТРАЯ МИГРАЦИЯ: Добавление поля target_participants_genitive
-- Выполните эти команды в phpMyAdmin или MySQL Workbench

-- 1. Добавить новое поле
ALTER TABLE competitions
ADD COLUMN target_participants_genitive TEXT AFTER target_participants;

-- 2. Заполнить данные для существующих конкурсов
UPDATE competitions
SET target_participants_genitive =
    CASE
        WHEN target_participants LIKE '%Воспитатели дошкольных образовательных учреждений%'
            THEN 'воспитателей дошкольных образовательных учреждений'

        WHEN target_participants LIKE '%Преподаватели медицинских колледжей%'
            THEN 'преподавателей медицинских колледжей'

        WHEN target_participants LIKE '%Учителя начальных классов%'
            THEN 'учителей начальных классов'

        WHEN target_participants LIKE '%Учителя%'
            THEN 'учителей общеобразовательных учреждений'

        WHEN target_participants LIKE '%Педагоги%'
            THEN 'педагогов образовательных учреждений'

        WHEN target_participants LIKE '%Методисты%'
            THEN 'методистов образовательных учреждений'

        WHEN target_participants LIKE '%Студенты%'
            THEN 'студентов педагогических учебных заведений'

        ELSE LOWER(target_participants)
    END
WHERE target_participants_genitive IS NULL OR target_participants_genitive = '';

-- 3. Проверка результата
SELECT id, title, target_participants, target_participants_genitive
FROM competitions
ORDER BY id;

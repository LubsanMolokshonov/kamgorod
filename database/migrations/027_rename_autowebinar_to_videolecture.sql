-- Миграция 027: Переименование автовебинаров в видеолекции

-- Шаг 1: Добавляем новое значение ENUM
ALTER TABLE webinars MODIFY COLUMN status ENUM('draft','scheduled','live','completed','autowebinar','videolecture') DEFAULT 'draft';

-- Шаг 2: Обновляем статус вебинаров
UPDATE webinars SET status = 'videolecture' WHERE status = 'autowebinar';

-- Шаг 3: Убираем старое значение из ENUM
ALTER TABLE webinars MODIFY COLUMN status ENUM('draft','scheduled','live','completed','videolecture') DEFAULT 'draft';

-- Шаг 4: Обновляем пользовательские тексты в touchpoint-ах
UPDATE autowebinar_email_touchpoints
SET email_subject = REPLACE(email_subject, 'автовебинар', 'видеолекцию')
WHERE email_subject LIKE '%автовебинар%';

UPDATE autowebinar_email_touchpoints
SET description = REPLACE(description, 'автовебинар', 'видеолекцию')
WHERE description LIKE '%автовебинар%';

UPDATE autowebinar_email_touchpoints
SET name = REPLACE(name, 'автовебинар', 'видеолекция')
WHERE name LIKE '%автовебинар%';

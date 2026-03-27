-- Миграция 060: Поддержка оплаты курсов из личного кабинета
-- Добавляет course_enrollment_id в order_items, расширяет статусы, добавляет user_id

-- 1. Добавить course_enrollment_id в order_items
ALTER TABLE order_items
  ADD COLUMN course_enrollment_id INT UNSIGNED NULL AFTER olympiad_registration_id,
  ADD INDEX idx_course_enrollment (course_enrollment_id);

-- 2. Расширить ENUM статусов (добавить 'paid')
ALTER TABLE course_enrollments
  MODIFY COLUMN status ENUM('new', 'contacted', 'enrolled', 'paid', 'cancelled') DEFAULT 'new';

-- 3. Добавить user_id в course_enrollments
ALTER TABLE course_enrollments
  ADD COLUMN user_id INT UNSIGNED NULL AFTER course_id,
  ADD INDEX idx_ce_user (user_id);

-- 4. Бэкфил user_id из таблицы users по email
UPDATE course_enrollments ce
  JOIN users u ON ce.email = u.email
  SET ce.user_id = u.id
  WHERE ce.user_id IS NULL;

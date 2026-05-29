-- 100: Сквозная атрибуция заявок — visit_id и yclid
-- Контекст: заявки на курсы часто теряли UTM (фронт слал utm_* только если они
-- были в URL/sessionStorage на момент сабмита). Теперь сервер восстанавливает
-- источник из визита, поэтому привязываем заявку к конкретному визиту (visit_id) —
-- это надёжнее хрупкого ym_uid (visits.session_id хранится с префиксом 'ym_').
-- yclid (Яндекс.Директ Click ID) нужен для точной сверки заявок с кликами Директа.
--
-- Идемпотентно: каждое ADD COLUMN/ADD KEY выполняется только если объекта ещё нет
-- (MySQL 8 не поддерживает ADD COLUMN IF NOT EXISTS). Без хранимых процедур и
-- DELIMITER — runner проекта (splitSqlStatements) делит файл по ';'.

SET NAMES utf8mb4;
SET CHARACTER SET utf8mb4;

-- course_enrollments.visit_id
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_enrollments ADD COLUMN visit_id BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_enrollments' AND COLUMN_NAME = 'visit_id');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- course_consultations.visit_id
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_consultations ADD COLUMN visit_id BIGINT UNSIGNED DEFAULT NULL',
    'SELECT 1') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_consultations' AND COLUMN_NAME = 'visit_id');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- visits.yclid
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE visits ADD COLUMN yclid VARCHAR(255) DEFAULT NULL',
    'SELECT 1') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'visits' AND COLUMN_NAME = 'yclid');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- course_enrollments.yclid
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_enrollments ADD COLUMN yclid VARCHAR(255) DEFAULT NULL',
    'SELECT 1') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_enrollments' AND COLUMN_NAME = 'yclid');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- course_consultations.yclid
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_consultations ADD COLUMN yclid VARCHAR(255) DEFAULT NULL',
    'SELECT 1') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_consultations' AND COLUMN_NAME = 'yclid');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Индекс course_enrollments.visit_id
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_enrollments ADD KEY idx_visit_id (visit_id)',
    'SELECT 1') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_enrollments' AND INDEX_NAME = 'idx_visit_id');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

-- Индекс course_consultations.visit_id
SET @ddl := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE course_consultations ADD KEY idx_visit_id (visit_id)',
    'SELECT 1') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_consultations' AND INDEX_NAME = 'idx_visit_id');
PREPARE s FROM @ddl; EXECUTE s; DEALLOCATE PREPARE s;

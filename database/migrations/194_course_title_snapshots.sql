-- Применить до переименований: старые заявки сохраняют название на момент миграции.
SET @snapshot_ddl = IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME='course_enrollments' AND COLUMN_NAME='course_title_snapshot')=0,
    'ALTER TABLE course_enrollments ADD COLUMN course_title_snapshot VARCHAR(1000) NULL',
    'SELECT 1');
PREPARE snapshot_stmt FROM @snapshot_ddl;
EXECUTE snapshot_stmt;
DEALLOCATE PREPARE snapshot_stmt;
UPDATE course_enrollments ce JOIN courses c ON c.id=ce.course_id
SET ce.course_title_snapshot=c.title WHERE ce.course_title_snapshot IS NULL OR ce.course_title_snapshot='';

CREATE TABLE IF NOT EXISTS catalog_change_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id VARCHAR(100) NOT NULL,
    entity_type VARCHAR(30) NOT NULL,
    entity_key VARCHAR(500) NOT NULL,
    old_values JSON NULL,
    new_values JSON NOT NULL,
    reverted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX catalog_history_package (package_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

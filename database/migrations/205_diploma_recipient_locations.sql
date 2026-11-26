-- NULL у старых конкурсов сохраняет прежнее заполнение из профиля.
-- Для новых олимпиад пустой введённый город нужно отличать от исторического пропуска.
SET @ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='registrations' AND COLUMN_NAME='participant_organization'),
    'SELECT 1',
    'ALTER TABLE registrations ADD COLUMN participant_organization VARCHAR(255) NULL, ADD COLUMN participant_city VARCHAR(255) NULL, ADD COLUMN supervisor_city VARCHAR(255) NULL, ALGORITHM=INSTANT');
PREPARE diploma_location_ddl FROM @ddl;
EXECUTE diploma_location_ddl;
DEALLOCATE PREPARE diploma_location_ddl;

SET @ddl = IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='olympiad_registrations' AND COLUMN_NAME='city_from_form'),
    'SELECT 1',
    'ALTER TABLE olympiad_registrations ADD COLUMN city_from_form TINYINT(1) NOT NULL DEFAULT 0, ADD COLUMN supervisor_city VARCHAR(255) NULL, ALGORITHM=INSTANT');
PREPARE diploma_location_ddl FROM @ddl;
EXECUTE diploma_location_ddl;
DEALLOCATE PREPARE diploma_location_ddl;

-- A/B-тест цен курсов: сохранение варианта при записи
ALTER TABLE course_enrollments
    ADD COLUMN ab_variant CHAR(1) DEFAULT NULL COMMENT 'A/B-тест цен: A/B/C';

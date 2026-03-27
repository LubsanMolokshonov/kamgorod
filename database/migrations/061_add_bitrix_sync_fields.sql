-- Добавление полей для отложенной синхронизации записей на курсы с Bitrix24
ALTER TABLE course_enrollments
  ADD COLUMN bitrix_attempts TINYINT UNSIGNED DEFAULT 0 AFTER bitrix_lead_id,
  ADD COLUMN bitrix_stage VARCHAR(30) NULL AFTER bitrix_attempts;

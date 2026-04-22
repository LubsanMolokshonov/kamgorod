-- Добавляем поля для трекинга сделки Bitrix24 и автопродвижения по воронке
-- для заявок на консультацию по курсам (аналогично course_enrollments).

ALTER TABLE course_consultations
    ADD COLUMN bitrix_lead_id INT NULL AFTER status,
    ADD COLUMN bitrix_stage VARCHAR(64) NULL AFTER bitrix_lead_id,
    ADD COLUMN bitrix_stage_updated_at TIMESTAMP NULL AFTER bitrix_stage,
    ADD COLUMN bitrix_attempts INT NOT NULL DEFAULT 0 AFTER bitrix_stage_updated_at,
    ADD INDEX idx_consultation_stage (status, bitrix_stage),
    ADD INDEX idx_consultation_bitrix_retry (bitrix_lead_id, bitrix_attempts);

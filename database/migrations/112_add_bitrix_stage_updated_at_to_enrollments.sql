-- course_enrollments: трекинг времени последней сверки этапа Bitrix24.
-- Нужно для cron/sync-course-deal-stages.php, который раз в 30 минут опрашивает
-- B24 и подтягивает актуальный STAGE_ID (включая сделки на рассрочку, чьи этапы
-- ведут только Битрикс-роботы, и обычные сделки, закрытые менеджером вручную).

ALTER TABLE course_enrollments
    ADD COLUMN bitrix_stage_updated_at DATETIME NULL AFTER bitrix_stage,
    ADD INDEX idx_enrollment_bitrix_sync (status, bitrix_lead_id, bitrix_stage_updated_at);

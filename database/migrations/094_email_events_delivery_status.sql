-- Email-трекинг: фактический статус доставки на уровне SMTP.
-- До этой миграции email_events писался ДО $mail->send(); если письмо отбивалось
-- (например, 554 SPAM от Яндекса во время warmup), запись всё равно попадала в БД
-- и раздувала знаменатель open_rate. Теперь EmailTracker помечает исход send():
--   pending  — запись создана, send() ещё не отработал
--   sent     — $mail->send() вернул true
--   failed   — send() кинул исключение или вернул false (SMTP reject и т.п.)
-- Исторические записи остаются с NULL и в дашборде считаются как доставленные.
ALTER TABLE email_events
    ADD COLUMN delivery_status ENUM('pending','sent','failed') NULL DEFAULT NULL AFTER sent_at,
    ADD COLUMN delivery_error  VARCHAR(500) NULL DEFAULT NULL AFTER delivery_status,
    ADD INDEX idx_email_events_delivery (delivery_status, sent_at);

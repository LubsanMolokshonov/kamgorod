-- Отметка об отправленном напоминании об окончании подписки.
-- Нужна для идемпотентности cron/subscription-reminders.php (не слать повторно
-- в окне за 3 дня до expires_at). Сбрасывается при продлении (last_renewed_at).
--
-- migrate.php игнорирует "Duplicate column" — повторный прогон безопасен.

ALTER TABLE user_subscriptions
    ADD COLUMN expiry_reminder_sent_at DATETIME NULL DEFAULT NULL AFTER last_renewed_at;

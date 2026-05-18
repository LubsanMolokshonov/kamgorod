-- 114: Колонка delivered_at для old_base_campaign_recipients.
-- Заполняется вебхуком Unisender Go (api/webhook/unisender.php) при событии 'delivered'.
-- До этого «доставлено» было равно «отправлено»; теперь это отдельная подтверждённая метрика.

ALTER TABLE old_base_campaign_recipients
    ADD COLUMN delivered_at DATETIME NULL AFTER sent_at;

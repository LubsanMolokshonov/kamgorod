-- Этап 2 (автопродление подписок): данные карты для ЛК + dunning-счётчики рекуррента.
--
--   card_last4 / card_type        — маска привязанной карты для показа в кабинете («Visa •••• 1234»).
--   renewal_attempt_count         — число попыток списания в текущем цикле (ограничивает dunning).
--   last_renewal_attempt_at       — время последней попытки (бэк-офф между попытками).
--   renewal_notice_sent_at        — идемпотентность письма-предупреждения за N дней до списания
--                                   (отдельно от expiry_reminder_sent_at — у того семантика
--                                   напоминания для auto_renew=0).
--
-- Отдельные ALTER на колонку — чтобы частичный повторный прогон доставил недостающие
-- (migrate.php выполняет statements по одному и не падает на ошибке отдельного ALTER).

ALTER TABLE user_subscriptions
    ADD COLUMN card_last4 VARCHAR(4) NULL DEFAULT NULL AFTER yookassa_payment_method_id;

ALTER TABLE user_subscriptions
    ADD COLUMN card_type VARCHAR(20) NULL DEFAULT NULL AFTER card_last4;

ALTER TABLE user_subscriptions
    ADD COLUMN renewal_attempt_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER card_type;

ALTER TABLE user_subscriptions
    ADD COLUMN last_renewal_attempt_at DATETIME NULL DEFAULT NULL AFTER renewal_attempt_count;

ALTER TABLE user_subscriptions
    ADD COLUMN renewal_notice_sent_at DATETIME NULL DEFAULT NULL AFTER expiry_reminder_sent_at;

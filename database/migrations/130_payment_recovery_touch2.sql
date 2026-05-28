-- Миграция 130: второе касание в цепочке восстановления упавших платежей.
-- Дата: 2026-05-28
--
-- Причина: recovery-письмо (PaymentRecoveryChain) — лучший канал дозакрытия
-- (29.5% открытий, 4.9% оплат), но касание всего одно. Добавляем второе письмо
-- для тех, кто не оплатил после первого. Таблица payment_recovery_email_log хранит
-- одну строку на заказ (PK = order_id), поэтому второе касание трекаем
-- отдельными колонками, а не новой строкой.
--
-- Идемпотентность — через трекинг миграций (таблица migrations).

ALTER TABLE payment_recovery_email_log
    ADD COLUMN touch2_status ENUM('pending','sent','failed','skipped') NULL DEFAULT NULL AFTER status,
    ADD COLUMN touch2_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER touch2_status,
    ADD COLUMN touch2_message_id VARCHAR(255) NULL AFTER touch2_attempts,
    ADD COLUMN touch2_error_message TEXT NULL AFTER touch2_message_id,
    ADD COLUMN touch2_sent_at TIMESTAMP NULL AFTER touch2_error_message;

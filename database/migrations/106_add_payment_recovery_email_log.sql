-- Лог recovery-писем для failed-заказов.
-- Используется cron/payment-recovery.php: один заказ — одно письмо.
-- PK на order_id гарантирует idempotency без отдельного INSERT IGNORE.

CREATE TABLE IF NOT EXISTS payment_recovery_email_log (
  order_id INT UNSIGNED NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  email VARCHAR(255) NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  message_id VARCHAR(255) NULL,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  sent_at TIMESTAMP NULL,
  INDEX idx_status_attempts (status, attempts),
  INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

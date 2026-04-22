-- Telegram alert log: дедупликация и история отправленных алертов
-- Используется TelegramNotifier для ограничения спама (rate-limit по alert_key)

CREATE TABLE IF NOT EXISTS telegram_alert_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    alert_key VARCHAR(190) NOT NULL,
    title VARCHAR(500) NOT NULL,
    context TEXT NULL,
    severity ENUM('info','warning','critical') NOT NULL DEFAULT 'critical',
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    http_code SMALLINT NULL,
    KEY idx_key_sent (alert_key, sent_at),
    KEY idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Лог разовой массовой рассылки-приглашения на вебинар.
-- Используется scripts/send_webinar_invitation.php для idempotency и учёта дневной квоты.

CREATE TABLE IF NOT EXISTS webinar_invitation_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  webinar_id INT NOT NULL,
  user_id INT NOT NULL,
  email VARCHAR(255) NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  error TEXT NULL,
  unisender_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_webinar_user (webinar_id, user_id),
  INDEX idx_status_webinar (status, webinar_id),
  INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

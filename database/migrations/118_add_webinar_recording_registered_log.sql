-- Лог разовой post-webinar рассылки записи ЗАРЕГИСТРИРОВАННЫМ участникам вебинара.
-- Парная таблица к webinar_recording_invitation_log (миграция 111): та шлёт холодной
-- аудитории, эта — тем, кто регистрировался. Используется
-- scripts/send_webinar_recording_to_registered.php для идемпотентности и ретраев.
-- Ключ — по webinar_registration_id (источник — webinar_registrations, user_id бывает NULL).

CREATE TABLE IF NOT EXISTS webinar_recording_registered_log (
  id INT AUTO_INCREMENT PRIMARY KEY,
  webinar_id INT NOT NULL,
  webinar_registration_id INT NOT NULL,
  user_id INT NULL,
  email VARCHAR(255) NOT NULL,
  status ENUM('pending','sent','failed','skipped') NOT NULL DEFAULT 'pending',
  sent_at DATETIME NULL,
  error TEXT NULL,
  unisender_id VARCHAR(64) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_webinar_reg (webinar_registration_id),
  INDEX idx_status_webinar (status, webinar_id),
  INDEX idx_sent_at (sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

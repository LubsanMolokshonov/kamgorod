-- 113: Email-рассылки по старой базе (импорт из «Старая база.csv»)
-- Три таблицы:
--   1) old_base_subscribers          — импортированная база подписчиков
--   2) old_base_campaigns            — конфигурация кампании
--   3) old_base_campaign_recipients  — план + журнал отправки per recipient
-- Плюс расширение ENUM email_events.email_type значением 'old_base'.

CREATE TABLE IF NOT EXISTS old_base_subscribers (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    full_name VARCHAR(255) NULL,
    status ENUM('active','unsubscribed','bounced','complained','suppressed') NOT NULL DEFAULT 'active',
    user_id INT UNSIGNED NULL COMMENT 'Линковка с users.id по email (если совпало)',
    source VARCHAR(64) NOT NULL DEFAULT 'csv_2026_05',
    imported_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME NULL,
    total_sent INT UNSIGNED NOT NULL DEFAULT 0,
    total_opened INT UNSIGNED NOT NULL DEFAULT 0,
    total_clicked INT UNSIGNED NOT NULL DEFAULT 0,
    total_converted INT UNSIGNED NOT NULL DEFAULT 0,
    bounce_count INT UNSIGNED NOT NULL DEFAULT 0,
    last_bounce_at DATETIME NULL,
    last_bounce_reason VARCHAR(255) NULL,
    notes TEXT NULL,
    UNIQUE KEY uniq_email (email),
    KEY idx_status (status),
    KEY idx_user_id (user_id),
    KEY idx_last_sent_at (last_sent_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS old_base_campaigns (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL COMMENT 'Используется как touchpoint_code в email_events',
    name VARCHAR(255) NOT NULL,
    subject VARCHAR(500) NOT NULL,
    from_name VARCHAR(120) NULL,
    from_email VARCHAR(190) NULL,
    html_body MEDIUMTEXT NOT NULL,
    plain_body TEXT NULL,
    cta_url VARCHAR(512) NULL,
    auto_utm TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Автоматически дополнять ?utm_source=email&utm_campaign={code}',
    audience_filter JSON NOT NULL,
    status ENUM('draft','scheduled','running','paused','completed','cancelled') NOT NULL DEFAULT 'draft',
    recipient_count INT UNSIGNED NOT NULL DEFAULT 0,
    start_date DATE NULL,
    send_window_start TIME NOT NULL DEFAULT '10:00:00',
    send_window_end TIME NOT NULL DEFAULT '18:00:00',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/Moscow',
    ramp_schedule JSON NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    UNIQUE KEY uniq_code (code),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS old_base_campaign_recipients (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    campaign_id INT UNSIGNED NOT NULL,
    subscriber_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NULL,
    email VARCHAR(255) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status ENUM('pending','sending','sent','failed','skipped','unsubscribed','bounced') NOT NULL DEFAULT 'pending',
    sent_at DATETIME NULL,
    message_id VARCHAR(64) NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    error_message VARCHAR(512) NULL,
    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_campaign_subscriber (campaign_id, subscriber_id),
    KEY idx_campaign_status_sched (campaign_id, status, scheduled_at),
    KEY idx_message_id (message_id),
    KEY idx_user_id (user_id),
    KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Расширить ENUM email_events.email_type — добавить 'old_base'.
ALTER TABLE email_events
    MODIFY COLUMN email_type ENUM('journey','webinar','publication','autowebinar','olympiad','course','course_promo','payment','old_base','other') NOT NULL;

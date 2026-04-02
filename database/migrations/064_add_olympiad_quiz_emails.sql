-- Migration: Add Olympiad Quiz Email System
-- Created: 2026-04-02
-- Description: Email-уведомления для этапов регистрации и прохождения теста олимпиад

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
SET CHARACTER SET utf8mb4;

-- Лог email-уведомлений для квиза (отдельно от дипломной цепочки)
CREATE TABLE IF NOT EXISTS olympiad_quiz_email_log (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL COMMENT 'ID пользователя',
    olympiad_id INT UNSIGNED NOT NULL COMMENT 'ID олимпиады',
    olympiad_result_id INT UNSIGNED NULL COMMENT 'ID результата теста (NULL для pre-quiz писем)',
    email VARCHAR(255) NOT NULL COMMENT 'Email получателя',
    email_type VARCHAR(50) NOT NULL COMMENT 'Тип письма: reg_welcome, reg_reminder_1h, quiz_success, quiz_success_reminder_24h, quiz_fail',
    status ENUM('pending', 'sent', 'failed', 'skipped') DEFAULT 'pending',
    scheduled_at DATETIME NOT NULL COMMENT 'Запланированное время отправки',
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    attempts INT UNSIGNED DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_user_olympiad_type (user_id, olympiad_id, email_type),
    INDEX idx_status_scheduled (status, scheduled_at),
    INDEX idx_user (user_id),
    INDEX idx_olympiad (olympiad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

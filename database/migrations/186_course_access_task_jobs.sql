-- Очередь задач на допуск к курсу после подтверждённой ЮKassa-оплаты.
-- Запись создаётся только из order-fulfillment с source='webhook', поэтому
-- оффлайн-оплаты Bitrix, подписки и local bypass сюда не попадают.

CREATE TABLE IF NOT EXISTS course_access_task_jobs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    course_enrollment_id INT UNSIGNED NOT NULL,
    bitrix_deal_id BIGINT UNSIGNED DEFAULT NULL,
    bitrix_task_id BIGINT UNSIGNED DEFAULT NULL,
    status ENUM('pending','processing','sent','failed') NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    locked_at DATETIME DEFAULT NULL,
    sent_at DATETIME DEFAULT NULL,
    last_error TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_course_access_task_order_enrollment (order_id, course_enrollment_id),
    KEY idx_course_access_task_queue (status, available_at, id),
    KEY idx_course_access_task_deal (bitrix_deal_id),
    KEY idx_course_access_task_task (bitrix_task_id),
    CONSTRAINT fk_course_access_task_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_course_access_task_enrollment
        FOREIGN KEY (course_enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

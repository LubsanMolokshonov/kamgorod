CREATE TABLE IF NOT EXISTS course_checkout_payments (
    enrollment_id INT UNSIGNED NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    attempt_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    attempt_started_at DATETIME DEFAULT NULL,
    attempt_payload JSON DEFAULT NULL,
    PRIMARY KEY (enrollment_id),
    UNIQUE KEY uq_course_checkout_order (order_id),
    UNIQUE KEY uq_course_checkout_attempt (attempt_key),
    CONSTRAINT fk_course_checkout_enrollment
        FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE,
    CONSTRAINT fk_course_checkout_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS course_payment_links (
    enrollment_id INT UNSIGNED NOT NULL,
    deal_id BIGINT UNSIGNED NOT NULL,
    comment_id BIGINT UNSIGNED DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    next_attempt_at DATETIME DEFAULT NULL,
    add_uncertain TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (enrollment_id),
    UNIQUE KEY uq_course_payment_link_deal (deal_id),
    KEY idx_course_payment_link_retry (next_attempt_at, expires_at),
    CONSTRAINT fk_course_payment_link_enrollment
        FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @course_payment_link_index_ddl = IF(
    NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_enrollments'
          AND INDEX_NAME = 'idx_course_enrollment_bitrix_id'
    ),
    'ALTER TABLE course_enrollments ADD INDEX idx_course_enrollment_bitrix_id (bitrix_lead_id), ALGORITHM=INPLACE, LOCK=NONE',
    'SELECT 1'
);
PREPARE course_payment_link_index_stmt FROM @course_payment_link_index_ddl;
EXECUTE course_payment_link_index_stmt;
DEALLOCATE PREPARE course_payment_link_index_stmt;

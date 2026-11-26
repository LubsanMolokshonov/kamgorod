-- Только подтверждённые новые условия. Исторические заявки сохраняют свой график.
CREATE TABLE IF NOT EXISTS course_installment_quotes (
    enrollment_id INT UNSIGNED NOT NULL PRIMARY KEY,
    total_amount DECIMAL(10,2) NOT NULL,
    months SMALLINT UNSIGNED NOT NULL,
    crm_synced_at DATETIME NULL,
    INDEX idx_installment_sync (crm_synced_at, enrollment_id),
    FOREIGN KEY (enrollment_id) REFERENCES course_enrollments(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

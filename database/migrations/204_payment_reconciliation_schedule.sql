-- Фоновая проверка читает по одной странице магазина за попытку и сохраняет границы и курсор.
CREATE TABLE IF NOT EXISTS payment_reconciliation_schedule (
    order_id INT UNSIGNED NOT NULL,
    task ENUM('covered','lookup_cart','lookup_course') NOT NULL,
    attempt_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
    next_check_at DATETIME NOT NULL,
    scan_until DATETIME NULL,
    scan_cursor VARCHAR(255) NULL,
    PRIMARY KEY (order_id,task),
    KEY idx_payment_reconciliation_due (task,next_check_at),
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

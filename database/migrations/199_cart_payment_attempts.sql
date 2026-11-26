-- Отпечаток исходного запроса в ЮKassa для безопасного повтора из любой сессии владельца.
CREATE TABLE IF NOT EXISTS cart_payment_attempts (
    order_id INT UNSIGNED NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (order_id),
    CONSTRAINT fk_cart_payment_attempt_order
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

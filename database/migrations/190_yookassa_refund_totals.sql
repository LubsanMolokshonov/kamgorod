-- Подтверждённая ЮKassa суммарная величина возвратов по платежу.
-- Отдельная таблица: не меняем исходную сумму заказа и поддерживаем покупки токенов.
CREATE TABLE IF NOT EXISTS yookassa_refund_totals (
    payment_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payment_amount DECIMAL(12,2) NOT NULL,
    refunded_amount DECIMAL(12,2) NOT NULL,
    currency CHAR(3) CHARACTER SET ascii NOT NULL,
    checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Атрибуция заказа к письму: хранится message_id последнего клика по email,
-- по которому пользователь дошёл до оплаты
ALTER TABLE orders
    ADD COLUMN email_message_id VARCHAR(64) NULL AFTER visit_id,
    ADD INDEX idx_orders_email_message (email_message_id);

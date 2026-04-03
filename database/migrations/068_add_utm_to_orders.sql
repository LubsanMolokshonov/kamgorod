-- Добавление UTM-полей в таблицу заказов для атрибуции
ALTER TABLE orders
    ADD COLUMN utm_source VARCHAR(255) NULL AFTER promotion_applied,
    ADD COLUMN utm_medium VARCHAR(255) NULL AFTER utm_source,
    ADD COLUMN utm_campaign VARCHAR(255) NULL AFTER utm_medium,
    ADD COLUMN utm_content VARCHAR(255) NULL AFTER utm_campaign,
    ADD COLUMN utm_term VARCHAR(255) NULL AFTER utm_content,
    ADD COLUMN visit_id BIGINT UNSIGNED NULL AFTER utm_term,
    ADD INDEX idx_orders_utm_source (utm_source),
    ADD INDEX idx_orders_visit (visit_id);

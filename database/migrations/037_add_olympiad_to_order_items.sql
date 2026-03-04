-- 037: Add olympiad_registration_id to order_items
-- Интеграция олимпиадных дипломов с единой корзиной и оплатой

ALTER TABLE order_items
    ADD COLUMN olympiad_registration_id INT UNSIGNED NULL AFTER webinar_certificate_id;

ALTER TABLE order_items
    ADD CONSTRAINT fk_order_items_olympiad_reg
    FOREIGN KEY (olympiad_registration_id) REFERENCES olympiad_registrations(id) ON DELETE CASCADE;

ALTER TABLE order_items
    ADD INDEX idx_order_items_olympiad_reg (olympiad_registration_id);

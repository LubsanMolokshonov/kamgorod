-- Migration 018: Add certificate support to order_items and extend publication_certificates
-- Allows order_items to contain publication certificates in addition to competition registrations

-- 1. Add city and publication_date to publication_certificates
ALTER TABLE publication_certificates
    ADD COLUMN city VARCHAR(255) NULL AFTER position,
    ADD COLUMN publication_date DATE NULL AFTER city;

-- 2. Add certificate_id column to order_items (nullable since items can be either registration or certificate)
ALTER TABLE order_items
    ADD COLUMN certificate_id INT UNSIGNED NULL AFTER registration_id;

-- 3. Make registration_id nullable (since items can be either registration or certificate)
ALTER TABLE order_items
    MODIFY COLUMN registration_id INT UNSIGNED NULL;

-- 4. Add foreign key for certificate_id
ALTER TABLE order_items
    ADD CONSTRAINT fk_order_items_certificate
    FOREIGN KEY (certificate_id) REFERENCES publication_certificates(id) ON DELETE CASCADE;

-- 5. Add index for certificate lookups
ALTER TABLE order_items
    ADD INDEX idx_order_items_certificate (certificate_id);

-- 6. Drop the old unique constraint (it prevents having certificates without registrations)
ALTER TABLE order_items
    DROP INDEX unique_order_registration;

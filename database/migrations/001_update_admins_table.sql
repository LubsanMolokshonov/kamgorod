-- Migration: Update admins table structure
-- Add missing fields for admin management

ALTER TABLE admins
ADD COLUMN email VARCHAR(100) UNIQUE AFTER username,
ADD COLUMN role ENUM('admin', 'superadmin') DEFAULT 'admin' AFTER password_hash,
ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER full_name,
ADD COLUMN last_login_at TIMESTAMP NULL AFTER is_active,
CHANGE COLUMN last_login last_login_old TIMESTAMP NULL;

-- Update existing admins (if any)
UPDATE admins SET email = CONCAT(username, '@pedagogy-platform.ru') WHERE email IS NULL;

-- Make email NOT NULL after setting defaults
ALTER TABLE admins
MODIFY COLUMN email VARCHAR(100) UNIQUE NOT NULL;

-- Remove old last_login column
ALTER TABLE admins
DROP COLUMN last_login_old;

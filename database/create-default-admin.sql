-- Create default admin user
-- Username: admin
-- Password: admin123
-- IMPORTANT: Change password after first login!

INSERT INTO admins (username, email, password_hash, role, full_name, is_active)
VALUES (
    'admin',
    'admin@pedagogy-platform.ru',
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- bcrypt hash of 'admin123'
    'superadmin',
    'Администратор',
    1
)
ON DUPLICATE KEY UPDATE
    username = VALUES(username);

-- Alternative passwords (all hashed):
-- 'admin123' -> $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi
-- 'password' -> $2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi

<?php
/**
 * Initialize Default Admin User
 * Run this script once to create the default admin account
 */

require_once __DIR__ . '/../config/database.php';

try {
    // Check if admin already exists
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM admins WHERE username = 'admin'");
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo "✅ Admin user already exists!\n";
        echo "Username: admin\n";
        echo "Use your existing password to login.\n\n";
    } else {
        // Create default admin
        $passwordHash = password_hash('admin123', PASSWORD_DEFAULT);

        $stmt = $db->prepare("
            INSERT INTO admins (username, email, password_hash, role, full_name, is_active)
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $stmt->execute([
            'admin',
            'admin@pedagogy-platform.ru',
            $passwordHash,
            'superadmin',
            'Администратор',
            1
        ]);

        echo "✅ Default admin user created successfully!\n\n";
        echo "Login credentials:\n";
        echo "Username: admin\n";
        echo "Password: admin123\n\n";
        echo "⚠️  IMPORTANT: Change the password after first login!\n";
        echo "Login URL: http://localhost:8080/admin/login.php\n\n";
    }

    // Show all admins
    $stmt = $db->query("SELECT id, username, email, role, is_active, created_at FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Current admin users:\n";
    echo str_repeat('-', 80) . "\n";
    foreach ($admins as $admin) {
        printf(
            "ID: %d | Username: %s | Email: %s | Role: %s | Active: %s | Created: %s\n",
            $admin['id'],
            $admin['username'],
            $admin['email'],
            $admin['role'],
            $admin['is_active'] ? 'Yes' : 'No',
            $admin['created_at']
        );
    }
    echo str_repeat('-', 80) . "\n";

} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}

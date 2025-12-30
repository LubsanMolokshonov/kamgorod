<?php
/**
 * Run database migration
 */

require_once __DIR__ . '/../config/database.php';

try {
    echo "Running migration: Update admins table structure...\n\n";

    // Helper function to check if column exists
    function columnExists($db, $table, $column) {
        $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE '{$column}'");
        return $stmt->rowCount() > 0;
    }

    // Add email column
    if (!columnExists($db, 'admins', 'email')) {
        $db->exec("ALTER TABLE admins ADD COLUMN email VARCHAR(100) UNIQUE AFTER username");
        echo "✅ Added email column\n";
    } else {
        echo "⏭️  email column already exists\n";
    }

    // Add role column
    if (!columnExists($db, 'admins', 'role')) {
        $db->exec("ALTER TABLE admins ADD COLUMN role ENUM('admin', 'superadmin') DEFAULT 'admin' AFTER password_hash");
        echo "✅ Added role column\n";
    } else {
        echo "⏭️  role column already exists\n";
    }

    // Add is_active column
    if (!columnExists($db, 'admins', 'is_active')) {
        $db->exec("ALTER TABLE admins ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER full_name");
        echo "✅ Added is_active column\n";
    } else {
        echo "⏭️  is_active column already exists\n";
    }

    // Add last_login_at column
    if (!columnExists($db, 'admins', 'last_login_at')) {
        $db->exec("ALTER TABLE admins ADD COLUMN last_login_at TIMESTAMP NULL AFTER is_active");
        echo "✅ Added last_login_at column\n";
    } else {
        echo "⏭️  last_login_at column already exists\n";
    }

    echo "\n✅ Migration completed successfully!\n\n";

    // Show table structure
    $stmt = $db->query("DESCRIBE admins");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Current admins table structure:\n";
    echo str_repeat('-', 80) . "\n";
    foreach ($columns as $col) {
        printf("%-20s %-30s %-10s %-10s\n",
            $col['Field'],
            $col['Type'],
            $col['Null'],
            $col['Key']
        );
    }
    echo str_repeat('-', 80) . "\n";

} catch (PDOException $e) {
    echo "❌ Migration error: " . $e->getMessage() . "\n";
    exit(1);
}

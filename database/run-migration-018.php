<?php
/**
 * Run Migration 018: Add certificate support to order_items
 */

require_once __DIR__ . '/../config/database.php';

echo "Running migration 018...\n";

try {
    // 1. Check if city column exists in publication_certificates
    $stmt = $db->query("SHOW COLUMNS FROM publication_certificates LIKE 'city'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE publication_certificates ADD COLUMN city VARCHAR(255) NULL AFTER position");
        echo "Added 'city' column to publication_certificates\n";
    } else {
        echo "'city' column already exists\n";
    }

    // 2. Check if publication_date column exists
    $stmt = $db->query("SHOW COLUMNS FROM publication_certificates LIKE 'publication_date'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE publication_certificates ADD COLUMN publication_date DATE NULL AFTER city");
        echo "Added 'publication_date' column to publication_certificates\n";
    } else {
        echo "'publication_date' column already exists\n";
    }

    // 3. Check if certificate_id column exists in order_items
    $stmt = $db->query("SHOW COLUMNS FROM order_items LIKE 'certificate_id'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE order_items ADD COLUMN certificate_id INT UNSIGNED NULL AFTER registration_id");
        echo "Added 'certificate_id' column to order_items\n";
    } else {
        echo "'certificate_id' column already exists\n";
    }

    // 4. Make registration_id nullable
    $db->exec("ALTER TABLE order_items MODIFY COLUMN registration_id INT UNSIGNED NULL");
    echo "Made 'registration_id' nullable\n";

    // 5. Check if foreign key exists
    $stmt = $db->query("
        SELECT CONSTRAINT_NAME
        FROM information_schema.TABLE_CONSTRAINTS
        WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'order_items'
        AND CONSTRAINT_NAME = 'fk_order_items_certificate'
    ");
    if ($stmt->rowCount() === 0) {
        $db->exec("
            ALTER TABLE order_items
            ADD CONSTRAINT fk_order_items_certificate
            FOREIGN KEY (certificate_id) REFERENCES publication_certificates(id) ON DELETE CASCADE
        ");
        echo "Added foreign key for certificate_id\n";
    } else {
        echo "Foreign key already exists\n";
    }

    // 6. Check if index exists
    $stmt = $db->query("SHOW INDEX FROM order_items WHERE Key_name = 'idx_order_items_certificate'");
    if ($stmt->rowCount() === 0) {
        $db->exec("ALTER TABLE order_items ADD INDEX idx_order_items_certificate (certificate_id)");
        echo "Added index for certificate_id\n";
    } else {
        echo "Index already exists\n";
    }

    // 7. Drop unique constraint if exists
    $stmt = $db->query("SHOW INDEX FROM order_items WHERE Key_name = 'unique_order_registration'");
    if ($stmt->rowCount() > 0) {
        $db->exec("ALTER TABLE order_items DROP INDEX unique_order_registration");
        echo "Dropped unique_order_registration constraint\n";
    } else {
        echo "Unique constraint already removed\n";
    }

    echo "\nMigration 018 completed successfully!\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

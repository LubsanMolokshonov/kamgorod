<?php
/**
 * Fix charset encoding for existing data in database
 */

require_once __DIR__ . '/config/database.php';

echo "=== Исправление кодировки данных ===\n\n";

// Tables and columns to fix
$tables = [
    'users' => ['full_name', 'organization', 'city', 'address', 'phone'],
    'competitions' => ['title', 'description', 'category'],
    'registrations' => ['nomination', 'work_title']
];

foreach ($tables as $table => $columns) {
    echo "Таблица: $table\n";

    // Get all rows
    $stmt = $db->query("SELECT * FROM $table");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        echo "  Нет записей\n\n";
        continue;
    }

    $fixed = 0;
    foreach ($rows as $row) {
        $id = $row['id'];
        $updates = [];
        $params = [];

        foreach ($columns as $column) {
            if (isset($row[$column]) && !empty($row[$column])) {
                // Try to fix double-encoded UTF-8
                $original = $row[$column];

                // If data is stored as latin1 but is actually UTF-8, we need to convert it
                $fixed_text = mb_convert_encoding($original, 'UTF-8', 'UTF-8');

                // Check if it looks like mojibake and try to fix it
                if (preg_match('/[Ð-Ñ][€-�]/', $original)) {
                    // This looks like UTF-8 stored as latin1, convert back
                    $fixed_text = mb_convert_encoding($original, 'UTF-8', 'ISO-8859-1');
                }

                if ($fixed_text !== $original) {
                    $updates[] = "`$column` = ?";
                    $params[] = $fixed_text;
                }
            }
        }

        if (!empty($updates)) {
            $params[] = $id;
            $sql = "UPDATE `$table` SET " . implode(', ', $updates) . " WHERE id = ?";
            $db->prepare($sql)->execute($params);
            $fixed++;
        }
    }

    echo "  Исправлено записей: $fixed\n\n";
}

echo "✓ Готово\n";

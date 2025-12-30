<?php
/**
 * Fix double-encoded UTF-8 data
 * Data was written as UTF-8 through latin1 connection, now being read as UTF-8
 */

require_once __DIR__ . '/config/database.php';

echo "=== Исправление двойной кодировки ===\n\n";

// Fix users table
echo "Исправление таблицы users...\n";
$db->exec("
    UPDATE users SET
        full_name = CONVERT(CAST(CONVERT(full_name USING latin1) AS BINARY) USING utf8mb4),
        organization = CONVERT(CAST(CONVERT(organization USING latin1) AS BINARY) USING utf8mb4),
        city = CONVERT(CAST(CONVERT(city USING latin1) AS BINARY) USING utf8mb4),
        profession = CONVERT(CAST(CONVERT(profession USING latin1) AS BINARY) USING utf8mb4)
    WHERE full_name REGEXP '[Ð-Ñ][€-�]'
");
echo "✓ Готово\n\n";

// Fix competitions table
echo "Исправление таблицы competitions...\n";
$db->exec("
    UPDATE competitions SET
        title = CONVERT(CAST(CONVERT(title USING latin1) AS BINARY) USING utf8mb4),
        description = CONVERT(CAST(CONVERT(description USING latin1) AS BINARY) USING utf8mb4),
        category = CONVERT(CAST(CONVERT(category USING latin1) AS BINARY) USING utf8mb4)
    WHERE title REGEXP '[Ð-Ñ][€-�]'
");
echo "✓ Готово\n\n";

// Fix registrations table
echo "Исправление таблицы registrations...\n";
$db->exec("
    UPDATE registrations SET
        nomination = CONVERT(CAST(CONVERT(nomination USING latin1) AS BINARY) USING utf8mb4),
        work_title = CONVERT(CAST(CONVERT(work_title USING latin1) AS BINARY) USING utf8mb4)
    WHERE nomination REGEXP '[Ð-Ñ][€-�]' OR work_title REGEXP '[Ð-Ñ][€-�]'
");
echo "✓ Готово\n\n";

echo "=== Проверка результатов ===\n";
$stmt = $db->query("SELECT full_name, organization, city FROM users LIMIT 1");
$user = $stmt->fetch();
echo "ФИО: " . $user['full_name'] . "\n";
echo "Организация: " . $user['organization'] . "\n";
echo "Город: " . $user['city'] . "\n\n";

echo "✓ Все данные исправлены\n";

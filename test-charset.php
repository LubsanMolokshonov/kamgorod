<?php
/**
 * Test Cyrillic encoding in database and PDF
 */

require_once __DIR__ . '/config/database.php';

// Test 1: Read from database
echo "=== ТЕСТ 1: Чтение из базы данных ===\n";
$stmt = $db->prepare("
    SELECT u.full_name, u.organization, u.city
    FROM users u
    LIMIT 1
");
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user) {
    echo "ФИО: " . $user['full_name'] . "\n";
    echo "Организация: " . $user['organization'] . "\n";
    echo "Город: " . $user['city'] . "\n";
} else {
    echo "Пользователи не найдены\n";
}

// Test 2: Check charset settings
echo "\n=== ТЕСТ 2: Настройки кодировки ===\n";
$result = $db->query('SHOW VARIABLES LIKE "character_set%"');
while ($row = $result->fetch()) {
    echo $row['Variable_name'] . ": " . $row['Value'] . "\n";
}

// Test 3: Test string
echo "\n=== ТЕСТ 3: Тестовая строка ===\n";
$testString = "Тестовая строка с кириллицей: АБВГДЕЖЗ абвгдежз 1234567890";
echo $testString . "\n";
echo "Длина строки: " . mb_strlen($testString, 'UTF-8') . " символов\n";

echo "\n✓ Тест завершен\n";

<?php
/**
 * Reset test data with correct UTF-8 encoding
 */

require_once __DIR__ . '/config/database.php';

echo "=== Сброс тестовых данных ===\n\n";

// Update user with correct UTF-8 data
echo "Обновление пользователя...\n";
$stmt = $db->prepare("
    UPDATE users
    SET full_name = ?,
        organization = ?,
        city = ?,
        profession = ?
    WHERE id = 1
");
$stmt->execute([
    'Иванов Иван Иванович',
    'МБОУ СОШ №1',
    'Москва',
    'Учитель начальных классов'
]);
echo "✓ Пользователь обновлен\n\n";

// Update competition
echo "Обновление конкурса...\n";
$stmt = $db->prepare("
    UPDATE competitions
    SET title = ?,
        description = ?,
        category = ?
    WHERE id = 1
");
$stmt->execute([
    'Культурное наследие России',
    'Всероссийский конкурс для педагогов и учащихся',
    'methodology'
]);
echo "✓ Конкурс обновлен\n\n";

// Update registration
echo "Обновление регистрации...\n";
$stmt = $db->prepare("
    UPDATE registrations
    SET nomination = ?,
        work_title = ?
    WHERE id = 1
");
$stmt->execute([
    'Методическая разработка',
    'Проект по изучению культурного наследия'
]);
echo "✓ Регистрация обновлена\n\n";

// Verify
echo "=== Проверка ===\n";
$stmt = $db->query("SELECT full_name, organization, city FROM users WHERE id = 1");
$user = $stmt->fetch();
echo "ФИО: " . $user['full_name'] . "\n";
echo "Организация: " . $user['organization'] . "\n";
echo "Город: " . $user['city'] . "\n\n";

$stmt = $db->query("SELECT title FROM competitions WHERE id = 1");
$comp = $stmt->fetch();
echo "Конкурс: " . $comp['title'] . "\n\n";

echo "✓ Готово\n";

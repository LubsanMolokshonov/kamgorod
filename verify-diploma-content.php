<?php
/**
 * Verify diploma content by checking what data was used
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Diploma.php';

echo "=== Проверка содержимого диплома ===\n\n";

$registrationId = 6;

// Get the data that would be used in the diploma
$stmt = $db->prepare("
    SELECT
        r.*,
        u.full_name as user_full_name,
        u.organization,
        u.city,
        c.title as competition_name
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN competitions c ON r.competition_id = c.id
    WHERE r.id = ?
");
$stmt->execute([$registrationId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Данные для диплома (ID регистрации: $registrationId):\n";
echo "───────────────────────────────────────────────\n";
echo "ФИО: " . $data['user_full_name'] . "\n";
echo "Организация: " . $data['organization'] . "\n";
echo "Город: " . $data['city'] . "\n";
echo "Конкурс: " . $data['competition_name'] . "\n";
echo "Номинация: " . $data['nomination'] . "\n";
echo "Работа: " . $data['work_title'] . "\n";
echo "───────────────────────────────────────────────\n\n";

// Check character encoding
echo "Проверка кодировки:\n";
echo "ФИО (hex): " . bin2hex(mb_substr($data['user_full_name'], 0, 10)) . "\n";
echo "Длина ФИО: " . mb_strlen($data['user_full_name'], 'UTF-8') . " символов\n";
echo "UTF-8 valid: " . (mb_check_encoding($data['user_full_name'], 'UTF-8') ? 'ДА' : 'НЕТ') . "\n\n";

// Find the latest diploma file
$stmt = $db->prepare("
    SELECT pdf_path, generated_at
    FROM diplomas
    WHERE registration_id = ? AND recipient_type = 'participant'
    ORDER BY generated_at DESC
    LIMIT 1
");
$stmt->execute([$registrationId]);
$diploma = $stmt->fetch(PDO::FETCH_ASSOC);

if ($diploma) {
    echo "Файл диплома: " . $diploma['pdf_path'] . "\n";
    echo "Дата генерации: " . $diploma['generated_at'] . "\n";
    $fullPath = __DIR__ . '/uploads/diplomas/' . $diploma['pdf_path'];
    echo "Размер файла: " . filesize($fullPath) . " байт\n";
}

echo "\n✓ Проверка завершена\n";

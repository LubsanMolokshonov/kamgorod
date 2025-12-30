<?php
/**
 * Test diploma generation with correct UTF-8 encoding
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Diploma.php';

echo "=== Тест генерации диплома ===\n\n";

$registrationId = 6;

try {
    $diploma = new Diploma($db);
    $pdfPath = $diploma->generate($registrationId, 'participant');

    echo "✓ Диплом сгенерирован: $pdfPath\n\n";

    // Read registration data to verify
    $stmt = $db->prepare("
        SELECT u.full_name, u.organization, u.city, c.title as competition_name,
               r.nomination, r.work_title
        FROM registrations r
        JOIN users u ON r.user_id = u.id
        JOIN competitions c ON r.competition_id = c.id
        WHERE r.id = ?
    ");
    $stmt->execute([$registrationId]);
    $data = $stmt->fetch();

    echo "Данные в дипломе должны быть:\n";
    echo "ФИО: " . $data['full_name'] . "\n";
    echo "Организация: " . $data['organization'] . "\n";
    echo "Город: " . $data['city'] . "\n";
    echo "Конкурс: " . $data['competition_name'] . "\n";
    echo "Номинация: " . $data['nomination'] . "\n";
    echo "Название работы: " . $data['work_title'] . "\n";

} catch (Exception $e) {
    echo "✗ Ошибка: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}

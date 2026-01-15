<?php
/**
 * AJAX: Get specializations by audience type slug
 * Returns JSON array of specializations
 */

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/AudienceType.php';

try {
    $audienceSlug = $_GET['audience'] ?? '';

    if (empty($audienceSlug)) {
        echo json_encode(['success' => true, 'specializations' => []]);
        exit;
    }

    $audienceTypeObj = new AudienceType($db);

    // Get audience type by slug
    $audienceType = $audienceTypeObj->getBySlug($audienceSlug);

    if (!$audienceType) {
        echo json_encode(['success' => false, 'error' => 'Тип аудитории не найден']);
        exit;
    }

    // Get specializations for this audience type
    $specializations = $audienceTypeObj->getSpecializations($audienceType['id']);

    echo json_encode([
        'success' => true,
        'audienceType' => [
            'id' => $audienceType['id'],
            'slug' => $audienceType['slug'],
            'name' => $audienceType['name']
        ],
        'specializations' => $specializations
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Ошибка сервера'
    ], JSON_UNESCAPED_UNICODE);
}

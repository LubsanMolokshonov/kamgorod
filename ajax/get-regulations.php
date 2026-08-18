<?php
/**
 * Get Competition Regulations AJAX Endpoint
 * Returns the regulations content for a specific competition
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/regulations-content.php';

// Get competition ID from request
$competitionId = $_GET['competition_id'] ?? '';

if (empty($competitionId)) {
    echo json_encode([
        'success' => false,
        'message' => 'ID конкурса не указан'
    ]);
    exit;
}

// Get competition data
$competitionObj = new Competition($db);
$competition = $competitionObj->getById($competitionId);

if (!$competition) {
    echo json_encode([
        'success' => false,
        'message' => 'Конкурс не найден'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'content' => generateRegulationsContent($competition),
    'download_url' => '/ajax/download-regulations.php?competition_id=' . (int)$competition['id']
]);

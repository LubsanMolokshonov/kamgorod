<?php
/**
 * Preview Diploma AJAX Endpoint
 * Generates a preview of the diploma with form data
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/DiplomaPreview.php';
require_once __DIR__ . '/../classes/Competition.php';

try {
    // Get template ID
    $templateId = $_POST['template_id'] ?? 1;

    if (!$templateId) {
        echo json_encode([
            'success' => false,
            'message' => 'Template ID is required'
        ]);
        exit;
    }

    // Get competition ID and fetch competition details
    $competitionId = $_POST['competition_id'] ?? null;
    $competitionName = '';

    if ($competitionId) {
        $competitionObj = new Competition($db);
        $competition = $competitionObj->getById($competitionId);
        if ($competition) {
            $competitionName = $competition['title'];
        }
    }

    // Collect form data
    $data = [
        'fio' => $_POST['fio'] ?? '',
        'email' => $_POST['email'] ?? '',
        'organization' => $_POST['organization'] ?? '',
        'city' => $_POST['city'] ?? '',
        'supervisor_name' => $_POST['supervisor_name'] ?? '',
        'nomination' => $_POST['nomination'] ?? '',
        'competition_type' => $_POST['competition_type'] ?? '',
        'work_title' => $_POST['work_title'] ?? '',
        'placement' => $_POST['placement'] ?? '',
        'participation_date' => $_POST['participation_date'] ?? '',
        'competition_name' => $competitionName
    ];

    // Generate preview with real data
    $preview = new DiplomaPreview($templateId, $data);
    $svgContent = $preview->generate();

    // Return SVG as data URI
    $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svgContent);

    echo json_encode([
        'success' => true,
        'preview_url' => $dataUri,
        'svg_content' => $svgContent
    ]);

} catch (Exception $e) {
    error_log('Preview error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate preview: ' . $e->getMessage()
    ]);
}

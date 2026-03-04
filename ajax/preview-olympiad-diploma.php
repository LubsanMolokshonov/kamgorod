<?php
/**
 * Preview Olympiad Diploma AJAX Endpoint
 * Generates a preview of the olympiad diploma with form data
 */

session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/DiplomaPreview.php';

try {
    $templateId = $_POST['template_id'] ?? 1;

    if (!$templateId) {
        echo json_encode([
            'success' => false,
            'message' => 'Template ID is required'
        ]);
        exit;
    }

    // Get olympiad title from result_id
    $olympiadTitle = '';
    $resultId = $_POST['result_id'] ?? null;

    if ($resultId) {
        $stmt = $db->prepare("
            SELECT o.title
            FROM olympiad_results r
            JOIN olympiads o ON r.olympiad_id = o.id
            WHERE r.id = ?
        ");
        $stmt->execute([$resultId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $olympiadTitle = $row['title'];
        }
    }

    $recipientType = $_POST['recipient_type'] ?? 'participant';

    $data = [
        'fio' => $_POST['fio'] ?? '',
        'email' => $_POST['email'] ?? '',
        'organization' => $_POST['organization'] ?? '',
        'city' => $_POST['city'] ?? '',
        'supervisor_name' => $_POST['supervisor_name'] ?? '',
        'supervisor_organization' => $_POST['supervisor_organization'] ?? '',
        'supervisor_city' => $_POST['supervisor_city'] ?? $_POST['city'] ?? '',
        'competition_type' => $_POST['competition_type'] ?? 'всероссийская',
        'placement' => $_POST['placement'] ?? '',
        'participation_date' => $_POST['participation_date'] ?? '',
        'competition_name' => $olympiadTitle
    ];

    $preview = new DiplomaPreview($templateId, $data, $recipientType, 'olympiad');
    $svgContent = $preview->generate();

    $dataUri = 'data:image/svg+xml;base64,' . base64_encode($svgContent);

    echo json_encode([
        'success' => true,
        'preview_url' => $dataUri,
        'svg_content' => $svgContent
    ]);

} catch (Exception $e) {
    error_log('Olympiad preview error: ' . $e->getMessage());

    echo json_encode([
        'success' => false,
        'message' => 'Failed to generate preview: ' . $e->getMessage()
    ]);
}

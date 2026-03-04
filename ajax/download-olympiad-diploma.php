<?php
/**
 * Download Olympiad Diploma AJAX Endpoint
 * Generates and serves olympiad diploma PDF files
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/OlympiadDiploma.php';
require_once __DIR__ . '/../includes/session.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Unauthorized');
}

// Get parameters
$registrationId = $_GET['id'] ?? null;
$recipientType = $_GET['type'] ?? 'participant';

if (!$registrationId) {
    http_response_code(400);
    die('Registration ID is required');
}

if (!in_array($recipientType, ['participant', 'supervisor'])) {
    http_response_code(400);
    die('Invalid recipient type');
}

try {
    // Verify ownership
    $stmt = $db->prepare("SELECT user_id FROM olympiad_registrations WHERE id = ?");
    $stmt->execute([$registrationId]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$reg || $reg['user_id'] != $_SESSION['user_id']) {
        http_response_code(403);
        die('Access denied');
    }

    $diploma = new OlympiadDiploma($db);

    // Generate or retrieve diploma
    $result = $diploma->generate($registrationId, $recipientType);

    if (!$result['success']) {
        http_response_code(400);
        die($result['message']);
    }

    // Get the PDF file path
    $pdfPath = __DIR__ . '/..' . $result['pdf_path'];

    if (!file_exists($pdfPath)) {
        http_response_code(404);
        die('Diploma file not found');
    }

    // Increment download counter
    $stmt = $db->prepare("
        SELECT id FROM olympiad_diplomas
        WHERE olympiad_registration_id = ? AND recipient_type = ?
    ");
    $stmt->execute([$registrationId, $recipientType]);
    $diplomaRecord = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($diplomaRecord) {
        $diploma->incrementDownloadCount($diplomaRecord['id']);
    }

    // Set headers for PDF download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="olympiad_diploma_' . $registrationId . '_' . $recipientType . '.pdf"');
    header('Content-Length: ' . filesize($pdfPath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    readfile($pdfPath);
    exit;

} catch (Exception $e) {
    error_log("Download olympiad diploma error: " . $e->getMessage());
    http_response_code(500);
    die('Server error');
}

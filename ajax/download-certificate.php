<?php
/**
 * Download Publication Certificate
 * Generates and streams the certificate PDF
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    die('Необходима авторизация');
}

// Get certificate ID
$certificateId = intval($_GET['id'] ?? 0);

if (!$certificateId) {
    http_response_code(400);
    die('ID свидетельства не указан');
}

$certObj = new PublicationCertificate($db);

// Verify ownership
if (!$certObj->verifyOwnership($certificateId, $_SESSION['user_id'])) {
    http_response_code(403);
    die('Доступ запрещён');
}

// Get certificate
$certificate = $certObj->getById($certificateId);

if (!$certificate) {
    http_response_code(404);
    die('Свидетельство не найдено');
}

// Check if paid
if ($certificate['status'] !== 'paid' && $certificate['status'] !== 'ready') {
    http_response_code(403);
    die('Свидетельство не оплачено');
}

// Generate if not ready
if ($certificate['status'] !== 'ready' || !$certificate['pdf_path']) {
    $result = $certObj->generate($certificateId);
    if (!$result['success']) {
        http_response_code(500);
        die('Ошибка генерации: ' . $result['message']);
    }
    $certificate = $certObj->getById($certificateId);
}

// Get PDF path
$pdfPath = __DIR__ . '/..' . $certificate['pdf_path'];

if (!file_exists($pdfPath)) {
    // Try to regenerate
    $result = $certObj->generate($certificateId);
    if (!$result['success']) {
        http_response_code(500);
        die('Файл свидетельства не найден');
    }
    $pdfPath = __DIR__ . '/..' . $result['pdf_path'];
}

// Stream file
$filename = 'svidetelstvo_' . $certificate['certificate_number'] . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($pdfPath));
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($pdfPath);
exit;

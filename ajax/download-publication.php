<?php
/**
 * Download Publication File
 * Streams the publication file to user and tracks download count
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';

// Get publication ID
$publicationId = intval($_GET['id'] ?? 0);

if (!$publicationId) {
    http_response_code(400);
    die('ID публикации не указан');
}

$database = new Database($db);
$publicationObj = new Publication($db);

// Get publication
$publication = $publicationObj->getById($publicationId);

if (!$publication) {
    http_response_code(404);
    die('Публикация не найдена');
}

// Check if published
if ($publication['status'] !== 'published') {
    // Allow download only for owner
    if (!isset($_SESSION['user_id']) || $_SESSION['user_id'] != $publication['user_id']) {
        http_response_code(403);
        die('Доступ запрещён');
    }
}

// Check file exists
if (!$publication['file_path']) {
    http_response_code(404);
    die('Файл не найден');
}

$filePath = __DIR__ . '/../uploads/publications/' . $publication['file_path'];

if (!file_exists($filePath)) {
    http_response_code(404);
    die('Файл не существует');
}

// Increment download count
$publicationObj->incrementDownloads($publicationId);

// Get file info
$filename = $publication['file_original_name'] ?: basename($publication['file_path']);
$filesize = filesize($filePath);
$mimeType = $publication['file_type'] ?: mime_content_type($filePath);

// Set headers for download
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: no-cache, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Output file
readfile($filePath);
exit;

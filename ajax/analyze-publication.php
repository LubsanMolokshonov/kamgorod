<?php
/**
 * Analyze Publication File AJAX Handler
 * Extracts text from uploaded file, sends to Yandex GPT for analysis,
 * returns suggested metadata (title, annotation, type, directions, subjects)
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../classes/DocumentExtractor.php';
require_once __DIR__ . '/../classes/YandexGPTModerator.php';
require_once __DIR__ . '/../includes/session.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

// Validate file exists
if (!isset($_FILES['publication_file']) || $_FILES['publication_file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'No file provided']);
    exit;
}

$file = $_FILES['publication_file'];

// Check for upload errors
if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'message' => 'File upload error']);
    exit;
}

// Validate file type
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedTypes = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'message' => 'Unsupported file type']);
    exit;
}

// Validate file size (10MB max)
if ($file['size'] > 10 * 1024 * 1024) {
    echo json_encode(['success' => false, 'message' => 'File too large']);
    exit;
}

try {
    // Log file info for debugging
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) mkdir($logDir, 0755, true);

    file_put_contents($logDir . '/moderation.log',
        date('Y-m-d H:i:s') . " [ANALYSIS] File received: name=" . $file['name'] .
        ", size=" . $file['size'] . ", mime=" . $mimeType .
        ", tmp=" . $file['tmp_name'] . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    // Extract text from file
    $extractor = new DocumentExtractor();
    $text = $extractor->extractText($file['tmp_name']);

    // Log extraction result for debugging
    file_put_contents($logDir . '/moderation.log',
        date('Y-m-d H:i:s') . " [ANALYSIS] Text extracted: " . mb_strlen($text) . " chars, first 500: " .
        json_encode(mb_substr($text, 0, 500), JSON_UNESCAPED_UNICODE) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );

    // Check if enough text was extracted
    if (mb_strlen($text) < 50) {
        echo json_encode([
            'success' => false,
            'message' => 'Could not extract enough text from the file'
        ]);
        exit;
    }

    // Analyze content with Yandex GPT
    $moderator = new YandexGPTModerator();
    $analysis = $moderator->analyzeContent($text);

    // Map slugs to IDs
    $typeObj = new PublicationType($db);
    $tagObj = new PublicationTag($db);

    $publicationTypeId = null;
    if (!empty($analysis['publication_type'])) {
        $type = $typeObj->getBySlug($analysis['publication_type']);
        if ($type) {
            $publicationTypeId = $type['id'];
        }
    }

    $directionIds = [];
    if (!empty($analysis['directions'])) {
        foreach ($analysis['directions'] as $slug) {
            $tag = $tagObj->getBySlug($slug);
            if ($tag && $tag['tag_type'] === 'direction') {
                $directionIds[] = intval($tag['id']);
            }
        }
    }

    $subjectIds = [];
    if (!empty($analysis['subjects'])) {
        foreach ($analysis['subjects'] as $slug) {
            $tag = $tagObj->getBySlug($slug);
            if ($tag && $tag['tag_type'] === 'subject') {
                $subjectIds[] = intval($tag['id']);
            }
        }
    }

    echo json_encode([
        'success' => true,
        'suggestions' => [
            'title' => $analysis['title'] ?? '',
            'annotation' => $analysis['annotation'] ?? '',
            'publication_type_id' => $publicationTypeId,
            'direction_ids' => $directionIds,
            'subject_ids' => $subjectIds,
        ],
    ]);

} catch (Exception $e) {
    error_log("Analyze publication error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Analysis failed: ' . $e->getMessage()
    ]);
}

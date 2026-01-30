<?php
/**
 * Create Certificate Payment AJAX Handler
 * Adds publication certificate to cart
 */

// Error handling - only log, don't display
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../includes/session.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Validate CSRF token
if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

// Get publication ID
$publicationId = intval($_POST['publication_id'] ?? 0);
if (!$publicationId) {
    echo json_encode(['success' => false, 'message' => 'ID публикации не указан']);
    exit;
}

try {
    $database = new Database($db);
    $publicationObj = new Publication($db);
    $certObj = new PublicationCertificate($db);
    $userObj = new User($db);

    // Get publication
    $publication = $publicationObj->getById($publicationId);
    if (!$publication) {
        echo json_encode(['success' => false, 'message' => 'Публикация не найдена']);
        exit;
    }

    // Get or create certificate
    $certificate = $certObj->getByPublicationId($publicationId);
    if (!$certificate) {
        // Create certificate with provided data
        $certificateId = $certObj->create([
            'publication_id' => $publicationId,
            'user_id' => $publication['user_id'],
            'author_name' => $_POST['author_name'] ?? $publication['author_name'],
            'organization' => $_POST['organization'] ?? '',
            'position' => $_POST['position'] ?? '',
            'city' => $_POST['city'] ?? '',
            'publication_date' => $_POST['publication_date'] ?? date('Y-m-d'),
            'template_id' => intval($_POST['template_id'] ?? 1),
            'price' => 149.00
        ]);
        $certificate = $certObj->getById($certificateId);
    } else {
        // Update certificate data
        $updateData = [
            'author_name' => $_POST['author_name'] ?? $certificate['author_name'],
            'organization' => $_POST['organization'] ?? $certificate['organization'],
            'position' => $_POST['position'] ?? $certificate['position'],
            'template_id' => intval($_POST['template_id'] ?? $certificate['template_id'])
        ];

        // Add optional fields (requires migration 018)
        if (!empty($_POST['city'])) {
            $updateData['city'] = $_POST['city'];
        }
        if (!empty($_POST['publication_date'])) {
            $updateData['publication_date'] = $_POST['publication_date'];
        }

        $database->update('publication_certificates', $updateData, 'id = ?', [$certificate['id']]);

        // Refresh certificate data
        $certificate = $certObj->getById($certificate['id']);
    }

    // Add certificate to cart
    addCertificateToCart($certificate['id']);

    // Set user session
    $_SESSION['user_id'] = $publication['user_id'];
    $user = $userObj->getById($publication['user_id']);
    if ($user) {
        $_SESSION['user_email'] = $user['email'];
    }

    echo json_encode([
        'success' => true,
        'redirect_url' => '/pages/cart.php',
        'certificate_id' => $certificate['id'],
        'message' => 'Свидетельство добавлено в корзину'
    ]);

} catch (Throwable $e) {
    error_log("Certificate add to cart error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Ошибка: ' . $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
}

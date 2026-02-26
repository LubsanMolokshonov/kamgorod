<?php
/**
 * Quick-Add Publication Certificate to Cart
 * Simplified version of create-certificate-payment.php for cart cross-sell.
 * Creates a publication certificate and adds it to the cart without redirecting.
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

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

// User must be logged in
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    echo json_encode(['success' => false, 'message' => 'Необходимо авторизоваться']);
    exit;
}

$publicationId = intval($_POST['publication_id'] ?? 0);
if (!$publicationId) {
    echo json_encode(['success' => false, 'message' => 'ID публикации не указан']);
    exit;
}

try {
    $publicationObj = new Publication($db);
    $certObj = new PublicationCertificate($db);
    $userObj = new User($db);

    // Get publication
    $publication = $publicationObj->getById($publicationId);
    if (!$publication) {
        echo json_encode(['success' => false, 'message' => 'Публикация не найдена']);
        exit;
    }

    // Verify ownership
    if ((int)$publication['user_id'] !== (int)$userId) {
        echo json_encode(['success' => false, 'message' => 'Доступ запрещён']);
        exit;
    }

    // Get or create certificate
    $certificate = $certObj->getByPublicationId($publicationId);
    if (!$certificate) {
        $user = $userObj->getById($userId);
        $certificateId = $certObj->create([
            'publication_id' => $publicationId,
            'user_id' => $userId,
            'author_name' => $user['full_name'] ?? '',
            'organization' => $user['organization'] ?? '',
            'position' => $user['profession'] ?? '',
            'city' => $user['city'] ?? '',
            'publication_date' => date('Y-m-d'),
            'template_id' => 1,
            'price' => 299.00,
        ]);
    } else {
        $certificateId = $certificate['id'];
    }

    // Add to cart
    addCertificateToCart($certificateId);

    echo json_encode([
        'success' => true,
        'message' => 'Свидетельство добавлено в корзину',
        'cart_count' => getCartCount(),
        'ecommerce' => [
            'id' => 'pub-' . $publicationId,
            'name' => $publication['title'] ?? '',
            'price' => 299,
            'category' => 'Публикации',
        ],
    ]);

} catch (Throwable $e) {
    error_log("Quick-add publication certificate error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    echo json_encode(['success' => false, 'message' => 'Произошла ошибка при добавлении']);
}

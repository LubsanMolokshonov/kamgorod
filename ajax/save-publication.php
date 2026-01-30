<?php
/**
 * Save Publication AJAX Handler
 * Creates publication and redirects to certificate page
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationCertificate.php';
require_once __DIR__ . '/../classes/FileUploader.php';
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

// Validate required fields
$required = ['email', 'author_name', 'organization', 'title', 'annotation', 'publication_type_id'];
foreach ($required as $field) {
    if (empty($_POST[$field])) {
        echo json_encode(['success' => false, 'message' => "Поле $field обязательно для заполнения"]);
        exit;
    }
}

// Validate email
$email = filter_var($_POST['email'], FILTER_VALIDATE_EMAIL);
if (!$email) {
    echo json_encode(['success' => false, 'message' => 'Некорректный email']);
    exit;
}

// Validate file
if (!isset($_FILES['publication_file']) || $_FILES['publication_file']['error'] === UPLOAD_ERR_NO_FILE) {
    echo json_encode(['success' => false, 'message' => 'Необходимо прикрепить файл публикации']);
    exit;
}

// Validate tags (at least one direction required)
$tagIds = $_POST['tag_ids'] ?? [];
if (empty($tagIds)) {
    echo json_encode(['success' => false, 'message' => 'Выберите хотя бы одно направление']);
    exit;
}

try {
    $database = new Database($db);
    $userObj = new User($db);
    $publicationObj = new Publication($db);
    $certObj = new PublicationCertificate($db);
    $uploader = new FileUploader();

    // Start transaction
    $db->beginTransaction();

    // Create or find user
    $user = $userObj->findByEmail($email);
    if (!$user) {
        $userId = $userObj->create([
            'email' => $email,
            'full_name' => $_POST['author_name'],
            'organization' => $_POST['organization'],
            'profession' => $_POST['position'] ?? ''
        ]);
    } else {
        $userId = $user['id'];
        // Update user info if needed
        $userObj->update($userId, [
            'full_name' => $_POST['author_name'],
            'organization' => $_POST['organization'],
            'profession' => $_POST['position'] ?? $user['profession']
        ]);
    }

    // Upload file
    $uploadResult = $uploader->upload($_FILES['publication_file']);
    if (!$uploadResult['success']) {
        throw new Exception($uploadResult['error']);
    }

    // Create publication
    $publicationData = [
        'user_id' => $userId,
        'title' => trim($_POST['title']),
        'annotation' => trim($_POST['annotation']),
        'publication_type_id' => intval($_POST['publication_type_id']),
        'file_path' => $uploadResult['path'],
        'file_original_name' => $uploadResult['original_name'],
        'file_size' => $uploadResult['size'],
        'file_type' => $uploadResult['type'],
        'tag_ids' => array_map('intval', $tagIds),
        'status' => 'pending',
        'certificate_status' => 'pending'
    ];

    $publicationId = $publicationObj->create($publicationData);

    // Create certificate record
    $certificateId = $certObj->create([
        'publication_id' => $publicationId,
        'user_id' => $userId,
        'author_name' => $_POST['author_name'],
        'organization' => $_POST['organization'],
        'position' => $_POST['position'] ?? '',
        'price' => 149.00
    ]);

    // Set session
    $_SESSION['user_id'] = $userId;
    $_SESSION['user_email'] = $email;

    // Generate session token for auto-login
    $sessionToken = $userObj->generateSessionToken($userId);
    setcookie('session_token', $sessionToken, time() + (30 * 24 * 60 * 60), '/', '', isset($_SERVER['HTTPS']), true);

    $db->commit();

    echo json_encode([
        'success' => true,
        'publication_id' => $publicationId,
        'certificate_id' => $certificateId,
        'redirect_url' => '/pages/publication-certificate.php?id=' . $publicationId,
        'message' => 'Публикация успешно создана'
    ]);

} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    // Delete uploaded file if exists
    if (isset($uploadResult) && $uploadResult['success']) {
        $uploader->delete($uploadResult['path']);
    }

    error_log("Save publication error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка сохранения: ' . $e->getMessage()]);
}

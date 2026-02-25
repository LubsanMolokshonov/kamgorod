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

    // Extract HTML content from uploaded file for inline display
    $htmlContent = null;
    try {
        require_once __DIR__ . '/../classes/DocumentExtractor.php';
        $extractor = new DocumentExtractor();
        $fullFilePath = __DIR__ . '/../uploads/publications/' . $uploadResult['path'];
        $htmlContent = $extractor->extractHtml($fullFilePath);

        // Ensure content is valid UTF-8 (external tools may return other encodings)
        if ($htmlContent && !mb_check_encoding($htmlContent, 'UTF-8')) {
            $htmlContent = mb_convert_encoding($htmlContent, 'UTF-8', 'CP1251');
        }

        // Safety limit: truncate if content exceeds 1MB
        if ($htmlContent && mb_strlen($htmlContent) > 1000000) {
            $htmlContent = mb_substr($htmlContent, 0, 1000000)
                . '<p><em>Содержание публикации сокращено из-за большого объёма.</em></p>';
        }
    } catch (Exception $extractError) {
        error_log("Content extraction failed: " . $extractError->getMessage());
        // $htmlContent remains null — publication is saved without inline content
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
        'content' => $htmlContent,
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

    // === AI Moderation via Yandex GPT ===
    $moderationStatus = 'pending';
    $moderationMessage = 'Публикация отправлена на модерацию.';

    try {
        require_once __DIR__ . '/../config/config.php';
        require_once __DIR__ . '/../classes/YandexGPTModerator.php';

        $moderator = new YandexGPTModerator();
        $moderationResult = $moderator->moderate(
            trim($_POST['title']),
            trim($_POST['annotation'])
        );

        if ($moderationResult['is_educational']) {
            $publicationObj->approve($publicationId);
            $publicationObj->update($publicationId, [
                'moderation_type' => 'auto_approved',
                'moderated_at' => date('Y-m-d H:i:s'),
                'gpt_confidence' => $moderationResult['confidence'],
            ]);
            $moderationStatus = 'approved';
            $moderationMessage = 'Публикация одобрена и размещена в журнале.';
        } else {
            $publicationObj->reject($publicationId, $moderationResult['reason']);
            $publicationObj->update($publicationId, [
                'moderation_type' => 'auto_rejected',
                'moderated_at' => date('Y-m-d H:i:s'),
                'gpt_confidence' => $moderationResult['confidence'],
            ]);
            $moderationStatus = 'rejected';
            $moderationMessage = 'Публикация отклонена: ' . $moderationResult['reason'];
        }

        // Write to audit log
        $database->insert('moderation_log', [
            'publication_id' => $publicationId,
            'action' => $moderationResult['is_educational'] ? 'auto_approved' : 'auto_rejected',
            'reason' => $moderationResult['reason'],
            'confidence' => $moderationResult['confidence'],
            'gpt_raw_response' => $moderationResult['raw_response'] ?? null,
        ]);

    } catch (Exception $moderationError) {
        // API failure — publication stays as 'pending' for manual review
        error_log("YandexGPT moderation error for publication #{$publicationId}: " . $moderationError->getMessage());

        try {
            $database->insert('moderation_log', [
                'publication_id' => $publicationId,
                'action' => 'api_failure',
                'reason' => $moderationError->getMessage(),
            ]);
            $publicationObj->update($publicationId, [
                'moderation_type' => 'pending_manual',
            ]);
        } catch (Exception $logError) {
            error_log("Failed to log moderation error: " . $logError->getMessage());
        }
    }
    // === End AI Moderation ===

    echo json_encode([
        'success' => true,
        'publication_id' => $publicationId,
        'certificate_id' => $certificateId,
        'redirect_url' => '/pages/publication-certificate.php?id=' . $publicationId,
        'message' => 'Публикация успешно создана',
        'moderation_status' => $moderationStatus,
        'moderation_message' => $moderationMessage,
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

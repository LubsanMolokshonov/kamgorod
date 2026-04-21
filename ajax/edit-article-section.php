<?php
/**
 * Редактирование секции сгенерированной статьи
 */

session_start();
set_time_limit(90);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/ArticleGenerator.php';
require_once __DIR__ . '/../includes/session.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Недействительный токен безопасности']);
    exit;
}

$sessionToken = $_POST['session_token'] ?? '';
$sectionId = $_POST['section_id'] ?? '';
$instructions = trim($_POST['instructions'] ?? '');

if (empty($sessionToken) || empty($sectionId) || empty($instructions)) {
    echo json_encode(['success' => false, 'message' => 'Заполните инструкцию по изменению']);
    exit;
}

try {
    $generator = new ArticleGenerator($db);
    $result = $generator->editSection($sessionToken, $sectionId, $instructions);

    echo json_encode([
        'success' => true,
        'section_id' => $result['section_id'],
        'updated_html' => $result['updated_html'],
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log("Edit article section error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Ошибка редактирования. Попробуйте ещё раз.']);
}

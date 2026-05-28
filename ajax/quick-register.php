<?php
/**
 * AJAX: быстрая регистрация/вход из воронки материалов (без перехода на /vhod).
 * Вызывается модалкой перед генерацией материала, чтобы не было регвола.
 *
 * POST: csrf, email, full_name, agreement
 * Ответ: { success: true, user_id } | { success: false, error, code }
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/material-tracking.php';

function qrRespond(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    qrRespond(['success' => false, 'error' => 'Method not allowed', 'code' => 'method'], 405);
}

if (!validateCSRFToken($_POST['csrf'] ?? '')) {
    qrRespond(['success' => false, 'error' => 'Сессия истекла, обновите страницу', 'code' => 'csrf'], 403);
}

$email    = trim((string)($_POST['email'] ?? ''));
$fullName = trim((string)($_POST['full_name'] ?? ''));
$agreement = isset($_POST['agreement']) && $_POST['agreement'];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    qrRespond(['success' => false, 'error' => 'Введите корректный email', 'code' => 'email'], 422);
}
if ($fullName === '') {
    qrRespond(['success' => false, 'error' => 'Введите ФИО', 'code' => 'full_name'], 422);
}
if (!$agreement) {
    qrRespond(['success' => false, 'error' => 'Нужно принять условия и политику конфиденциальности', 'code' => 'agreement'], 422);
}

try {
    $stmt = $db->prepare("SELECT id, email FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $_SESSION['user_id']    = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
    } else {
        $stmt = $db->prepare("INSERT INTO users (email, full_name) VALUES (?, ?)");
        $stmt->execute([$email, mb_substr($fullName, 0, 255)]);
        $_SESSION['user_id']    = (int)$db->lastInsertId();
        $_SESSION['user_email'] = $email;
    }

    $userId = (int)$_SESSION['user_id'];

    // Атрибуция привлечения из воронки материалов
    persistMaterialUtmToUser($db, $userId);

    // Привязать анонимные превью-материалы к пользователю
    claimAnonymousMaterials($db, $userId);

    // Стартовый бонус (идемпотентно)
    (new UserTokens($db))->grantSignupBonusIfNeeded($userId);

    // Если у пользователя есть непривязанное превью — запланировать дожим preview_abandon
    try {
        require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
        (new MaterialTokenEmailChain($db))->schedulePreviewAbandon($userId);
    } catch (Throwable $e) {
        error_log('quick-register schedulePreviewAbandon: ' . $e->getMessage());
    }

    qrRespond(['success' => true, 'user_id' => $userId]);
} catch (Throwable $e) {
    error_log('quick-register error: ' . $e->getMessage());
    qrRespond(['success' => false, 'error' => 'Ошибка регистрации, попробуйте позже', 'code' => 'internal'], 500);
}

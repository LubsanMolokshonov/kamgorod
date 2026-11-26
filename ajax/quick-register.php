<?php
/**
 * AJAX: быстрая регистрация/вход из воронки материалов (без перехода на /vhod).
 * Вызывается модалкой перед генерацией материала, чтобы не было регвола.
 *
 * POST: csrf, email, full_name, agreement
 * Ответ: { success: true, confirmation_required, message } | { success: false, error, code }
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

require_once __DIR__ . '/../includes/account-login-mail.php';
try {
    $return = safeAccountReturnPath($_POST['return'] ?? '/kabinet/');
    requestAccountLoginEmail($db, $email, $fullName, $return);
    qrRespond(['success' => true, 'confirmation_required' => true,
        'message' => 'Откройте письмо и подтвердите вход. Вы вернётесь к материалу.']);
} catch (DomainException $e) {
    qrRespond(['success' => false, 'error' => $e->getMessage()], 429);
} catch (Throwable $e) {
    error_log('Письмо быстрого входа: ' . get_class($e));
    qrRespond(['success' => false, 'error' => 'Не удалось отправить письмо. Попробуйте позже.'], 503);
}

<?php
/**
 * Magic Auth Handler
 * Авто-авторизация по HMAC-токену из email-ссылок
 *
 * URL: /pages/magic-auth.php?token=XXXXX&redirect=/pages/cabinet.php
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

$token = $_GET['token'] ?? '';
$redirect = $_GET['redirect'] ?? '/pages/cabinet.php';

// Валидация redirect — только внутренние URL
if (!$redirect || $redirect[0] !== '/' || strpos($redirect, '//') === 0) {
    $redirect = '/pages/cabinet.php';
}

// Если пользователь уже авторизован — просто редиректим
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $redirect);
    exit;
}

// Валидируем токен
$userId = validateMagicToken($token);

if (!$userId) {
    // Токен невалидный или просрочен — отправляем на логин
    header('Location: /pages/login.php?redirect=' . urlencode($redirect));
    exit;
}

// Находим пользователя в БД
$userObj = new User($db);
$user = $userObj->getById($userId);

if (!$user) {
    header('Location: /pages/login.php?redirect=' . urlencode($redirect));
    exit;
}

// Создаём сессию
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_email'] = $user['email'];

// Устанавливаем cookie session_token для дальнейшего автовхода (30 дней)
$sessionToken = $userObj->generateSessionToken($user['id']);
setcookie(
    'session_token',
    $sessionToken,
    time() + (30 * 24 * 60 * 60),
    '/',
    '',
    isset($_SERVER['HTTPS']),
    true
);

// Восстановить корзину из pending-регистраций
$stmt = $db->prepare("SELECT id FROM registrations WHERE user_id = ? AND status = 'pending'");
$stmt->execute([$user['id']]);
$pendingRegs = $stmt->fetchAll(PDO::FETCH_COLUMN);
if (!empty($pendingRegs)) {
    $_SESSION['cart'] = $pendingRegs;
}

// Редирект на целевую страницу
header('Location: ' . $redirect);
exit;

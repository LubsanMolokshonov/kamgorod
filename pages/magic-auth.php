<?php
/**
 * Magic Auth Handler
 * Авто-авторизация по HMAC-токену из email-ссылок.
 *
 * Поддерживаемые форматы (оба идут сюда после .htaccess rewrite или напрямую):
 *   /m/<token>                       → token=<token>
 *   /m/<token>/<b64_redirect>        → token=<token>&r=<b64>
 *   /pages/magic-auth.php?token=...&redirect=...   (legacy, для уже отправленных писем)
 *
 * Опциональный параметр mid=<32hex> — message_id из письма; если передан,
 * выставляется email_mid cookie/session (для атрибуции письмо→оплата) ровно так же,
 * как это делает /api/email-track/click.php — magic-ссылки не оборачиваются в click-tracker,
 * но атрибуцию мы хотим сохранить.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';
require_once __DIR__ . '/../includes/session.php';

$token = $_GET['token'] ?? '';

// Redirect: новый параметр r= (base64url) приоритетнее legacy redirect=.
$redirect = '/kabinet/';
if (!empty($_GET['r'])) {
    $decodedR = base64url_decode((string)$_GET['r']);
    if ($decodedR !== false && $decodedR !== '') {
        $redirect = $decodedR;
    }
} elseif (!empty($_GET['redirect'])) {
    $redirect = (string)$_GET['redirect'];
}

// Защита от open-redirect: только относительные пути, без protocol-relative.
if (!$redirect || $redirect[0] !== '/' || strpos($redirect, '//') === 0) {
    $redirect = '/kabinet/';
}

// Пробрасываем UTM-параметры в redirect URL и сохраняем в cookie на 90 дней.
// Cookie переживает закрытие браузера и переход из почтового клиента — это то,
// что страхует атрибуцию для холодных пользователей, которые попали в email-каплю
// прямо после первого визита и кликают magic-link через сутки в новой сессии.
$utmParams = [];
$allowedUtm = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
foreach ($allowedUtm as $key) {
    $value = isset($_GET[$key]) ? mb_substr(trim((string)$_GET[$key]), 0, 255) : '';
    if ($value !== '') {
        $utmParams[$key] = $value;
        setcookie('_fgos_' . $key, $value, [
            'expires'  => time() + 90 * 24 * 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => false,  // visit-tracker.js должен иметь доступ для синхронизации с sessionStorage
            'samesite' => 'Lax',
        ]);
    }
}
if (!empty($utmParams)) {
    $separator = strpos($redirect, '?') !== false ? '&' : '?';
    $redirect .= $separator . http_build_query($utmParams);
}

// Email message_id для атрибуции (опционально). Magic-ссылки не оборачиваются в click.php,
// поэтому email_mid выставляем здесь — иначе атрибуция письмо→оплата потеряется.
$mid = $_GET['mid'] ?? '';
if ($mid && preg_match('~^[a-f0-9]{32}$~', $mid)) {
    // Регистрируем клик (зеркало api/email-track/click.php): magic-ссылки не
    // оборачиваются в click-tracker, иначе клики по ним невидимы в email_events.
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $db->prepare(
            "INSERT INTO email_click_events (message_id, url, clicked_at, ip_address, user_agent)
             VALUES (?, ?, NOW(), ?, ?)"
        )->execute([$mid, SITE_URL . $redirect, $ip, $ua]);
        $db->prepare(
            "UPDATE email_events
                SET clicks_count = clicks_count + 1,
                    first_clicked_at = IFNULL(first_clicked_at, NOW()),
                    last_clicked_at = NOW(),
                    opened_at = IFNULL(opened_at, NOW())
              WHERE message_id = ?"
        )->execute([$mid]);
    } catch (\Throwable $e) {
        error_log('magic-auth click log: ' . $e->getMessage());
    }
    $_SESSION['email_mid'] = $mid;
    setcookie('email_mid', $mid, [
        'expires'  => time() + 30 * 24 * 3600,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Если пользователь уже авторизован — просто редиректим
if (isset($_SESSION['user_id'])) {
    header('Location: ' . $redirect);
    exit;
}

// Diagnostic log: логируем неудачи (не сам токен — он содержит HMAC-подпись).
$logFail = function (string $reason) use ($token, $mid): void {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '-';
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? '-'), 0, 200);
    error_log(sprintf(
        'magic-auth: %s token_len=%d mid=%s ip=%s ua=%s',
        $reason, strlen((string)$token), $mid !== '' ? $mid : '-', $ip, $ua
    ));
};

// Валидируем токен
$userId = $token !== '' ? validateMagicToken($token) : false;
if (!$userId) {
    $logFail($token === '' ? 'no_token' : 'invalid_token');
    header('Location: /pages/login.php?redirect=' . urlencode($redirect));
    exit;
}

// Находим пользователя в БД
$userObj = new User($db);
$user = $userObj->getById($userId);

if (!$user) {
    $logFail('user_not_found');
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

// Server-side cart: смержить гостевую сессионную корзину в cart_items и подтянуть
// записи с других устройств. Источник истины для залогиненных — таблица cart_items.
mergeSessionCartToDb((int)$user['id']);

// Редирект на целевую страницу
header('Location: ' . $redirect);
exit;

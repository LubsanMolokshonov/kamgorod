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

$token = is_string($_GET['token'] ?? null) ? $_GET['token'] : '';
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');

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
$redirect = safeAccountReturnPath($redirect);

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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && is_string($mid) && preg_match('~^[a-f0-9]{32}$~', $mid)) {
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
$oneTime = str_starts_with($token, 'v2_');
$userId = $oneTime ? (emailMagicClaims($token)['user_id'] ?? false) : ($token !== '' ? validateMagicToken($token) : false);
if (!$userId) {
    $logFail($token === '' ? 'no_token' : 'invalid_token');
    header('Location: /vhod/?return=' . urlencode($redirect));
    exit;
}

// Находим пользователя в БД
$userObj = new User($db);
$user = $userObj->getById($userId);

if (!$user) {
    $logFail('user_not_found');
    header('Location: /vhod/?return=' . urlencode($redirect));
    exit;
}

$current = verifiedAccountId($db);
$switching = $current !== null && $current !== (int)$userId;
if ($current !== (int)$userId) {
    $csrf = $_POST['csrf_token'] ?? null;
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !is_string($csrf) || !validateCSRFToken($csrf)) {
        require_once __DIR__ . '/../includes/account-confirmation.php';
        renderAccountConfirmation(null, false, $switching, $redirect);
        exit;
    }
}
if ($oneTime && $current !== (int)$userId && !redeemEmailMagicToken($db, $token)) {
    header('Location: /vhod/?return=' . rawurlencode($redirect), true, 303);
    exit;
}
if ($current !== (int)$userId) establishVerifiedAccount($db, (int)$userId);
header('Location: ' . $redirect, true, 303);
exit;

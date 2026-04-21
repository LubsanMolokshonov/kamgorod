<?php
/**
 * Email click-tracking redirect.
 * Принимает ?mid=<32hex>&u=<base64url>, валидирует target против whitelist хостов,
 * логирует клик и редиректит на target. В сессию и cookie кладёт email_mid —
 * он используется при создании заказа для прямой атрибуции «письмо → оплата».
 */

session_start();
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

$mid = isset($_GET['mid']) ? trim((string)$_GET['mid']) : '';
$u   = isset($_GET['u'])   ? trim((string)$_GET['u'])   : '';

// Декодирование base64url → URL
$target = '';
if ($u !== '') {
    $padded = $u . str_repeat('=', (4 - strlen($u) % 4) % 4);
    $decoded = base64_decode(strtr($padded, '-_', '+/'), true);
    if ($decoded !== false) $target = $decoded;
}

// Защита от open-redirect: только http(s) на разрешённые хосты
$isAllowed = false;
if ($target !== '' && preg_match('~^https?://~i', $target)) {
    $parts = parse_url($target);
    $host  = $parts['host'] ?? '';
    if ($host !== '') {
        $allowed = defined('EMAIL_TRACK_ALLOWED_HOSTS')
            ? EMAIL_TRACK_ALLOWED_HOSTS
            : [parse_url(SITE_URL, PHP_URL_HOST)];
        foreach ($allowed as $allowedHost) {
            $allowedHost = strtolower(trim($allowedHost));
            if ($allowedHost === '') continue;
            $hostLc = strtolower($host);
            if ($hostLc === $allowedHost || str_ends_with($hostLc, '.' . $allowedHost)) {
                $isAllowed = true;
                break;
            }
        }
    }
}

if (!$isAllowed) {
    // Небезопасный target — редиректим на главную, не раскрывая email_mid
    header('Location: ' . SITE_URL, true, 302);
    exit;
}

// Регистрация клика
if (preg_match('~^[a-f0-9]{32}$~', $mid)) {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);

        $stmt = $db->prepare(
            "INSERT INTO email_click_events (message_id, url, clicked_at, ip_address, user_agent)
             VALUES (?, ?, NOW(), ?, ?)"
        );
        $stmt->execute([$mid, $target, $ip, $ua]);

        $stmt = $db->prepare(
            "UPDATE email_events
                SET clicks_count = clicks_count + 1,
                    first_clicked_at = IFNULL(first_clicked_at, NOW()),
                    last_clicked_at = NOW(),
                    opened_at = IFNULL(opened_at, NOW())
              WHERE message_id = ?"
        );
        $stmt->execute([$mid]);

        // Привязка mid к сессии / cookie для атрибуции при оплате
        $_SESSION['email_mid'] = $mid;
        setcookie('email_mid', $mid, [
            'expires'  => time() + 30 * 24 * 3600,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (\Throwable $e) {
        error_log('email-track/click: ' . $e->getMessage());
    }
}

header('Location: ' . $target, true, 302);
exit;

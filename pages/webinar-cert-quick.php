<?php
/**
 * Quick router: тихая регистрация на вебинар (если её нет) + редирект на оплату сертификата.
 * Используется в массовой post-webinar рассылке (см. scripts/send_webinar_recording_invitation.php).
 *
 * Вход:  ?webinar_id=18  (whitelist — только разрешённые id, чтобы случайным GET не плодить регистрации)
 * Выход: 302 на /pages/webinar-certificate.php?registration_id=...&autopay=1
 *
 * Авторизация:
 *   - если пришли по magic-link, /pages/magic-auth.php уже залогинил пользователя;
 *   - иначе пробуем session_token cookie;
 *   - иначе — на /pages/login.php?redirect=...
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../includes/session.php';

// === whitelist (расширять по необходимости — только активные «recording mass» кампании) ===
$ALLOWED_WEBINAR_IDS = [18];

$webinarId = (int)($_GET['webinar_id'] ?? 0);
if (!in_array($webinarId, $ALLOWED_WEBINAR_IDS, true)) {
    http_response_code(404);
    header('Location: /vebinary/');
    exit;
}

// Auto-login по cookie, если сессии нет
if (!isset($_SESSION['user_id']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);
    if ($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_email'] = $user['email'];
    }
}

if (!isset($_SESSION['user_id'])) {
    $redirectUrl = '/pages/webinar-cert-quick.php?webinar_id=' . $webinarId;
    header('Location: /pages/login.php?redirect=' . urlencode($redirectUrl));
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Подтянуть пользователя для тихой регистрации
$userStmt = $db->prepare("SELECT email, full_name, phone, organization, city FROM users WHERE id = ? LIMIT 1");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);
if (!$user) {
    header('Location: /pages/login.php');
    exit;
}

// Существующая регистрация?
$regStmt = $db->prepare(
    "SELECT id FROM webinar_registrations
     WHERE webinar_id = ? AND (user_id = ? OR LOWER(email) = LOWER(?))
     ORDER BY id DESC LIMIT 1"
);
$regStmt->execute([$webinarId, $userId, $user['email']]);
$existing = $regStmt->fetch(PDO::FETCH_ASSOC);

if ($existing) {
    $registrationId = (int)$existing['id'];
} else {
    // Тихая регистрация: НЕ вызываем WebinarEmailJourney — никаких confirmation/reminder писем.
    $insStmt = $db->prepare(
        "INSERT INTO webinar_registrations
            (webinar_id, user_id, email, full_name, phone, organization, city,
             status, registration_source, utm_source, utm_medium, utm_campaign)
         VALUES (?, ?, ?, ?, ?, ?, ?, 'registered', 'mass_recording_invite', ?, ?, ?)"
    );
    $insStmt->execute([
        $webinarId,
        $userId,
        $user['email'],
        $user['full_name'],
        $user['phone'] ?? null,
        $user['organization'] ?? null,
        $user['city'] ?? null,
        $_GET['utm_source']   ?? 'email',
        $_GET['utm_medium']   ?? 'recording_mass',
        $_GET['utm_campaign'] ?? null,
    ]);
    $registrationId = (int)$db->lastInsertId();
}

header('Location: /pages/webinar-certificate.php?registration_id=' . $registrationId . '&autopay=1');
exit;

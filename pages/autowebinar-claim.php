<?php
/**
 * Autowebinar Claim — точка приземления magic-link из разовой рассылки
 * по записи автовебинара (см. scripts/send_autowebinar_recording_invite.php).
 *
 * Поток: письмо → /m/<token>/<b64> → magic-auth логинит пользователя →
 * редирект сюда → авто-регистрация на видеолекцию + авто-зачёт теста →
 * редирект на оформление диплома (pages/webinar-certificate.php).
 *
 * Параметр: ?w=<webinar_id> (по умолчанию вебинар «Полезное лето. Особый ребёнок»).
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';

// Авто-логин по cookie, если сессии нет (на случай прямого захода без magic-auth)
if (!isset($_SESSION['user_id']) && isset($_COOKIE['session_token'])) {
    $userObj = new User($db);
    $user = $userObj->findBySessionToken($_COOKIE['session_token']);
    if ($user) {
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['user_email'] = $user['email'];
    }
}

// Целевой вебинар
$webinarId = (int)($_GET['w'] ?? 0);
if ($webinarId <= 0) {
    $row = $db->query("SELECT id FROM webinars WHERE slug = 'poleznoe-leto-osobyj-rebenok' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $webinarId = (int)($row['id'] ?? 0);
}

// Требуется авторизация (magic-link уже должен был залогинить)
if (!isset($_SESSION['user_id'])) {
    $redirect = '/pages/autowebinar-claim.php?w=' . $webinarId;
    header('Location: /pages/login.php?redirect=' . urlencode($redirect));
    exit;
}

$userId = (int)$_SESSION['user_id'];

// Проверяем вебинар
$webinarObj = new Webinar($db);
$webinar = $webinarObj->getById($webinarId);
if (!$webinar) {
    header('Location: /pages/webinars.php');
    exit;
}

// Данные пользователя для регистрации
$uStmt = $db->prepare("SELECT id, email, full_name, phone, organization, city FROM users WHERE id = ?");
$uStmt->execute([$userId]);
$userRow = $uStmt->fetch(PDO::FETCH_ASSOC);
if (!$userRow) {
    header('Location: /pages/webinars.php');
    exit;
}

$regObj = new WebinarRegistration($db);

// 1) Регистрация по клику (без дублей)
$registration = $regObj->getByWebinarAndEmail($webinarId, $userRow['email']);
if ($registration) {
    $registrationId = (int)$registration['id'];
} else {
    $registrationId = (int)$regObj->create([
        'webinar_id'          => $webinarId,
        'user_id'             => $userId,
        'full_name'           => $userRow['full_name'] ?: $userRow['email'],
        'email'               => $userRow['email'],
        'phone'               => $userRow['phone'] ?? null,
        'organization'        => $userRow['organization'] ?? null,
        'city'                => $userRow['city'] ?? null,
        'utm_source'          => 'email',
        'utm_medium'          => 'recording_invite',
        'utm_campaign'        => 'autowebinar-recording-' . $webinarId,
        'registration_source' => 'autowebinar_recording_invite',
        'skip_bitrix24'       => true,
    ]);
}

if ($registrationId > 0) {
    // 2) Авто-зачёт теста (если ещё не пройден) — чтобы диплом оформлялся сразу
    $hasResult = $db->prepare("SELECT id FROM webinar_quiz_results WHERE registration_id = ? AND passed = 1 LIMIT 1");
    $hasResult->execute([$registrationId]);
    if (!$hasResult->fetch()) {
        $ins = $db->prepare(
            "INSERT INTO webinar_quiz_results (webinar_id, user_id, registration_id, score, total_questions, passed, answers, completed_at)
             VALUES (?, ?, ?, 5, 5, 1, '{}', NOW())"
        );
        $ins->execute([$webinarId, $userId, $registrationId]);
    }

    // 3) Глушим автовебинарную email-цепочку для этой регистрации
    //    (рассылку с материалами человек уже получил — дубли от cron не нужны)
    $db->prepare(
        "INSERT IGNORE INTO autowebinar_email_log (registration_id, user_id, touchpoint_id, email, status, scheduled_at)
         SELECT ?, ?, t.id, ?, 'skipped', NOW()
         FROM autowebinar_email_touchpoints t WHERE t.is_active = 1"
    )->execute([$registrationId, $userId, $userRow['email']]);

    // 4) На оформление диплома
    header('Location: /pages/webinar-certificate.php?registration_id=' . $registrationId);
    exit;
}

// Фолбэк, если регистрация не создалась
header('Location: /vebinar/' . $webinar['slug'] . '/');
exit;

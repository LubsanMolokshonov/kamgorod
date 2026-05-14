<?php
/**
 * Рассылка: запись вебинара «Особый ребёнок: 10 шагов» (13.05.2026)
 * Запись + презентация + анкета обратной связи + сертификат (magic-link).
 * Только зарегистрированным участникам. Через Unisender Go (EmailDispatcher).
 *
 * Запуск:
 *   php scripts/send_recording_osobyj_rebenok.php --test email@test.com   # тест одному
 *   php scripts/send_recording_osobyj_rebenok.php --dry-run               # без отправки
 *   php scripts/send_recording_osobyj_rebenok.php                         # боевая рассылка
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';

set_time_limit(0);

$dryRun = in_array('--dry-run', $argv ?? []);
$testEmail = null;
$testIdx = array_search('--test', $argv ?? []);
if ($testIdx !== false && isset($argv[$testIdx + 1])) {
    $testEmail = $argv[$testIdx + 1];
}

// === Параметры рассылки ===
$webinarSlug     = 'osobyj-rebenok-10-shagov';
$recordingUrl    = 'https://clck.ru/3TdXMc';
$presentationUrl = 'https://clck.ru/3TdXRD';
$feedbackUrl     = 'https://clck.ru/3TcR6n';

$webinarStmt = $db->prepare("SELECT * FROM webinars WHERE slug = ? LIMIT 1");
$webinarStmt->execute([$webinarSlug]);
$webinar = $webinarStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) {
    die("Вебинар со slug '{$webinarSlug}' не найден\n");
}
$webinarId = (int)$webinar['id'];

echo "=== Рассылка: запись вебинара ===\n";
echo "Вебинар: {$webinar['title']} (ID: {$webinarId})\n";
echo "Режим: " . ($testEmail ? "ТЕСТ ({$testEmail})" : ($dryRun ? "DRY RUN" : "РАССЫЛКА")) . "\n";
echo "---\n\n";

$unsubStmt = $db->prepare("SELECT email FROM email_unsubscribes");
$unsubStmt->execute();
$unsubscribed = array_flip(
    array_map('strtolower', array_column($unsubStmt->fetchAll(PDO::FETCH_ASSOC), 'email'))
);
echo "Отписавшихся: " . count($unsubscribed) . "\n";

if ($testEmail) {
    $userStmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$testEmail]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow) {
        die("Пользователь с email {$testEmail} не найден\n");
    }

    $userId = (int)$userRow['id'];
    $regCheck = $db->prepare("SELECT id FROM webinar_registrations WHERE webinar_id = ? AND user_id = ?");
    $regCheck->execute([$webinarId, $userId]);
    $existingReg = $regCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingReg) {
        $regId = (int)$existingReg['id'];
    } else {
        $insertReg = $db->prepare(
            "INSERT INTO webinar_registrations (webinar_id, user_id, email, full_name, registration_source) VALUES (?, ?, ?, ?, 'test')"
        );
        $insertReg->execute([$webinarId, $userId, $userRow['email'], $userRow['full_name']]);
        $regId = (int)$db->lastInsertId();
        echo "Создана тестовая регистрация для {$testEmail} (reg #{$regId})\n";
    }

    $users = [[
        'id' => $userId,
        'email' => $userRow['email'],
        'full_name' => $userRow['full_name'],
        'registration_id' => $regId,
    ]];
} else {
    $regStmt = $db->prepare("
        SELECT wr.id as registration_id, wr.user_id,
               COALESCE(u.full_name, wr.full_name) as display_name,
               COALESCE(u.email, wr.email) as display_email
        FROM webinar_registrations wr
        LEFT JOIN users u ON wr.user_id = u.id
        WHERE wr.webinar_id = ? AND wr.status = 'registered'
        ORDER BY wr.id
    ");
    $regStmt->execute([$webinarId]);
    $registrations = $regStmt->fetchAll(PDO::FETCH_ASSOC);

    $users = [];
    $seenEmails = [];

    foreach ($registrations as $reg) {
        $emailKey = strtolower($reg['display_email']);
        if (!$emailKey || isset($seenEmails[$emailKey])) {
            continue;
        }
        $seenEmails[$emailKey] = true;

        $users[] = [
            'id' => $reg['user_id'] ? (int)$reg['user_id'] : null,
            'email' => $reg['display_email'],
            'full_name' => $reg['display_name'],
            'registration_id' => (int)$reg['registration_id'],
        ];
    }
}

$totalUsers = count($users);

if ($totalUsers === 0) {
    die("Зарегистрированные участники не найдены\n");
}

echo "Участников для рассылки: {$totalUsers}\n\n";
echo "=== Отправка писем ===\n\n";

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($users as $index => $user) {
    $userId   = $user['id'];
    $email    = $user['email'];
    $fullName = $user['full_name'];
    $regId    = $user['registration_id'];

    if (!$testEmail && isset($unsubscribed[strtolower($email)])) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    if (($index + 1) % 50 === 0 || $index === 0) {
        $pct = round(($index + 1) / $totalUsers * 100, 1);
        echo "--- Прогресс: " . ($index + 1) . " / {$totalUsers} ({$pct}%) ---\n";
    }

    if ($dryRun) {
        echo "[DRY] {$fullName} <{$email}> (reg #{$regId})\n";
        $sent++;
        continue;
    }

    try {
        if ($userId && $regId) {
            $certificate_url = generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $regId);
            $cabinet_url     = generateMagicUrl($userId, '/pages/cabinet.php?tab=events');
        } elseif ($userId) {
            $cabinet_url     = generateMagicUrl($userId, '/pages/cabinet.php?tab=events');
            $certificate_url = $cabinet_url;
        } else {
            $certificate_url = SITE_URL . '/pages/webinar-certificate.php';
            $cabinet_url     = SITE_URL . '/pages/cabinet.php?tab=events';
        }

        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribe_url  = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        $user_name         = $fullName;
        $webinar_title     = $webinar['title'];
        $certificate_price = $webinar['certificate_price'] ?? 200;
        $certificate_hours = $webinar['certificate_hours'] ?? 2;
        $recording_url     = $recordingUrl;
        $presentation_url  = $presentationUrl;
        $feedback_url      = $feedbackUrl;
        $site_url          = SITE_URL;

        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_recording_osobyj_rebenok.php';
        $htmlBody = ob_get_clean();

        $result = \EmailDispatcher::send([
            'to_email'        => $email,
            'to_name'         => $fullName,
            'subject'         => $email_subject,
            'html'            => $htmlBody,
            'unsubscribe_url' => $unsubscribe_url,
            'meta' => [
                'email_type'      => 'webinar',
                'touchpoint_code' => 'recording_osobyj_rebenok',
                'user_id'         => $userId,
            ],
        ]);

        $unisenderId = $result['unisender_id'] ?? '';
        echo "[SENT] {$fullName} <{$email}> (reg #{$regId}) {$unisenderId}\n";
        $sent++;

        usleep(200000);

    } catch (\Throwable $e) {
        echo "[FAIL] {$fullName} <{$email}> — " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Результат ===\n";
echo "Всего участников: {$totalUsers}\n";
echo "Отправлено: {$sent}\n";
echo "Пропущено: {$skipped}\n";
echo "Ошибки: {$failed}\n";

$mode = $testEmail ? "TEST({$testEmail})" : ($dryRun ? "DRY_RUN" : "SEND");
$logMessage = "[" . date('Y-m-d H:i:s') . "] RECORDING_OSOBYJ_REBENOK | Mode: {$mode} | Total: {$totalUsers}, Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}\n";
$logFile = BASE_PATH . '/logs/webinar-email-journey.log';
@error_log($logMessage, 3, $logFile);

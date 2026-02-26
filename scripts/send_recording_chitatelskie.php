<?php
/**
 * Рассылка: запись вебинара «Читательские марафоны» + презентация + анкета + сертификат + следующий вебинар
 * Рассылается ТОЛЬКО зарегистрированным участникам вебинара.
 *
 * Запуск:
 *   php scripts/send_recording_chitatelskie.php --test email@test.com   # тест одному
 *   php scripts/send_recording_chitatelskie.php --dry-run               # проверка без отправки
 *   php scripts/send_recording_chitatelskie.php                         # рассылка всем зарегистрированным
 */

// Только CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

set_time_limit(0);

// === Параметры CLI ===
$dryRun = in_array('--dry-run', $argv ?? []);
$testEmail = null;
$testIdx = array_search('--test', $argv ?? []);
if ($testIdx !== false && isset($argv[$testIdx + 1])) {
    $testEmail = $argv[$testIdx + 1];
}

// === Константы вебинара ===
$webinarId = 6;
$recordingUrl = 'https://clck.ru/3S4HUn';
$presentationUrl = 'https://clck.ru/3S4HKT';
$feedbackUrl = 'https://clck.ru/3S3Wvm';
$nextWebinarTitle = 'Как сохранить ресурс и не потерять качество работы при росте требований?';
$nextWebinarUrl = 'https://fgos.pro/vebinar/kak-sokhranit-resurs';

// === Получаем данные вебинара ===
$webinarStmt = $db->prepare("SELECT * FROM webinars WHERE id = ?");
$webinarStmt->execute([$webinarId]);
$webinar = $webinarStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) {
    die("Вебинар ID {$webinarId} не найден\n");
}

echo "=== Рассылка: запись вебинара ===\n";
echo "Вебинар: {$webinar['title']} (ID: {$webinarId})\n";
echo "Режим: " . ($testEmail ? "ТЕСТ ({$testEmail})" : ($dryRun ? "DRY RUN" : "РАССЫЛКА")) . "\n";
echo "---\n\n";

// === Загружаем отписки (O(1) lookup) ===
$unsubStmt = $db->prepare("SELECT email FROM email_unsubscribes");
$unsubStmt->execute();
$unsubscribed = array_flip(
    array_map('strtolower', array_column($unsubStmt->fetchAll(PDO::FETCH_ASSOC), 'email'))
);
echo "Отписавшихся: " . count($unsubscribed) . "\n";

// === Загружаем зарегистрированных участников ===
if ($testEmail) {
    // Тестовый режим — ищем пользователя по email
    $userStmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$testEmail]);
    $users = $userStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        die("Пользователь с email {$testEmail} не найден\n");
    }

    // Проверяем регистрацию — если нет, создаём
    $userId = $users[0]['id'];
    $regCheck = $db->prepare("SELECT id FROM webinar_registrations WHERE webinar_id = ? AND user_id = ?");
    $regCheck->execute([$webinarId, $userId]);
    $existingReg = $regCheck->fetch(PDO::FETCH_ASSOC);

    if ($existingReg) {
        $userRegMap = [$userId => $existingReg['id']];
    } else {
        // Создаём временную регистрацию для теста
        $insertReg = $db->prepare(
            "INSERT INTO webinar_registrations (webinar_id, user_id, email, full_name, registration_source) VALUES (?, ?, ?, ?, 'test')"
        );
        $insertReg->execute([$webinarId, $userId, $users[0]['email'], $users[0]['full_name']]);
        $userRegMap = [$userId => $db->lastInsertId()];
        echo "Создана тестовая регистрация для {$testEmail}\n";
    }
} else {
    // Боевой режим — только зарегистрированные участники
    $regStmt = $db->prepare("
        SELECT wr.id as registration_id, wr.user_id, wr.email, wr.full_name,
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
    $userRegMap = [];
    $seenEmails = [];

    foreach ($registrations as $reg) {
        $email = strtolower($reg['display_email']);
        // Дедупликация по email
        if (isset($seenEmails[$email])) {
            continue;
        }
        $seenEmails[$email] = true;

        $userId = $reg['user_id'];
        $users[] = [
            'id' => $userId,
            'email' => $reg['display_email'],
            'full_name' => $reg['display_name'],
        ];
        if ($userId) {
            $userRegMap[$userId] = $reg['registration_id'];
        }
    }
}

$totalUsers = count($users);

if ($totalUsers === 0) {
    die("Зарегистрированные участники не найдены\n");
}

echo "Участников для рассылки: {$totalUsers}\n\n";

// ============================================
// Отправка писем
// ============================================
echo "=== Отправка писем ===\n\n";

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($users as $index => $user) {
    $userId = $user['id'];
    $email = $user['email'];
    $fullName = $user['full_name'];

    // Проверяем отписку (кроме тестового режима)
    if (!$testEmail && isset($unsubscribed[strtolower($email)])) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    // Получаем registration_id
    $regId = $userRegMap[$userId] ?? null;

    // Прогресс каждые 50 писем
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
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        if (!empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD)) {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USERNAME;
            $mail->Password = SMTP_PASSWORD;

            if (SMTP_PORT == 465) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif (SMTP_PORT == 587) {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            }
        } else {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $fullName);

        // Генерация magic-link для сертификата
        $certificate_url = '';
        $cabinet_url = '';
        if ($userId && $regId) {
            $certificate_url = generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $regId);
            $cabinet_url = generateMagicUrl($userId, '/pages/cabinet.php?tab=webinars');
        } elseif ($userId) {
            $cabinet_url = generateMagicUrl($userId, '/pages/cabinet.php?tab=webinars');
            $certificate_url = $cabinet_url; // fallback
        } else {
            $certificate_url = SITE_URL . '/pages/webinar-certificate.php';
            $cabinet_url = SITE_URL . '/pages/cabinet.php?tab=webinars';
        }

        // Unsubscribe
        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        // Переменные шаблона
        $user_name = $fullName;
        $webinar_title = $webinar['title'];
        $certificate_price = $webinar['certificate_price'] ?? 200;
        $certificate_hours = $webinar['certificate_hours'] ?? 2;
        $recording_url = $recordingUrl;
        $presentation_url = $presentationUrl;
        $feedback_url = $feedbackUrl;
        $next_webinar_title = $nextWebinarTitle;
        $next_webinar_url = $nextWebinarUrl;
        $site_url = SITE_URL;

        // Рендер HTML-шаблона
        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_recording_chitatelskie.php';
        $htmlBody = ob_get_clean();

        // Plain text версия
        $textBody = "Здравствуйте, {$fullName}!\n\n";
        $textBody .= "25 февраля состоялся вебинар «{$webinar_title}».\n";
        $textBody .= "Запись уже доступна — смотрите в любое удобное время!\n\n";
        $textBody .= "Смотреть запись: {$recordingUrl}\n\n";
        $textBody .= "Презентация с полезными материалами: {$presentationUrl}\n\n";
        $textBody .= "Анкета обратной связи: {$feedbackUrl}\n\n";
        $textBody .= "Получите сертификат участника ({$certificate_hours} ак. часа, {$certificate_price} руб.):\n";
        $textBody .= $certificate_url . "\n\n";
        $textBody .= "Следующий вебинар: «{$nextWebinarTitle}»\n";
        $textBody .= "5 марта в 14:00 по Москве\n";
        $textBody .= "{$nextWebinarUrl}\n\n";
        $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $textBody .= SITE_URL . "\n\n";
        $textBody .= "Отписаться: {$unsubscribe_url}\n";

        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader($email_subject, 'UTF-8', 'B'); // из шаблона
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Заголовки отписки
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->send();

        echo "[SENT] {$fullName} <{$email}> (reg #{$regId})\n";
        $sent++;

        // Пауза 200мс между письмами
        usleep(200000);

    } catch (Exception $e) {
        echo "[FAIL] {$fullName} <{$email}> — " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ============================================
// РЕЗУЛЬТАТ
// ============================================
echo "\n=== Результат ===\n";
echo "Всего участников: {$totalUsers}\n";
echo "Отправлено: {$sent}\n";
echo "Пропущено: {$skipped}\n";
echo "Ошибки: {$failed}\n";

// Логирование
$mode = $testEmail ? "TEST({$testEmail})" : ($dryRun ? "DRY_RUN" : "SEND");
$logMessage = "[" . date('Y-m-d H:i:s') . "] RECORDING_CHITATELSKIE | Mode: {$mode} | Total: {$totalUsers}, Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}\n";
$logFile = BASE_PATH . '/logs/webinar-email-journey.log';
error_log($logMessage, 3, $logFile);

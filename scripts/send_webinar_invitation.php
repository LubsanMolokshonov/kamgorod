<?php
/**
 * Массовая рассылка: приглашение на вебинар
 * Отправляется пользователям из таблицы users, которые НЕ зарегистрированы на указанный вебинар.
 *
 * UTM: utm_source=email&utm_medium=invite&utm_campaign=webinar-chitatelskie-marafony
 *
 * Запуск:
 *   php scripts/send_webinar_invitation.php --test email@test.com   # тест одному
 *   php scripts/send_webinar_invitation.php --dry-run               # проверка без отправки
 *   php scripts/send_webinar_invitation.php                         # рассылка всем
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

// === Параметры вебинара ===
$webinarSlug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony';

// === Получаем данные вебинара ===
$webinarStmt = $db->prepare("
    SELECT w.*, s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo
    FROM webinars w
    LEFT JOIN speakers s ON w.speaker_id = s.id
    WHERE w.slug = ?
");
$webinarStmt->execute([$webinarSlug]);
$webinar = $webinarStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) {
    die("Вебинар со slug '{$webinarSlug}' не найден\n");
}
$webinarId = $webinar['id'];

echo "=== Массовая рассылка: приглашение на вебинар ===\n";
echo "Вебинар: {$webinar['title']} (ID: {$webinarId})\n";
echo "Дата: {$webinar['scheduled_at']}\n";
echo "Режим: " . ($testEmail ? "ТЕСТ ({$testEmail})" : ($dryRun ? "DRY RUN" : "РАССЫЛКА")) . "\n";
echo "UTM: utm_source=email&utm_medium=invite&utm_campaign=webinar-chitatelskie-marafony\n";
echo "---\n\n";

// === Загружаем отписки (O(1) lookup) ===
$unsubStmt = $db->prepare("SELECT email FROM email_unsubscribes");
$unsubStmt->execute();
$unsubscribed = array_flip(
    array_map('strtolower', array_column($unsubStmt->fetchAll(PDO::FETCH_ASSOC), 'email'))
);
echo "Отписавшихся: " . count($unsubscribed) . "\n";

// === Загружаем пользователей, которые НЕ зарегистрированы на вебинар ===
if ($testEmail) {
    $userStmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$testEmail]);
} else {
    $userStmt = $db->prepare("
        SELECT u.id, u.email, u.full_name
        FROM users u
        WHERE u.email NOT IN (
            SELECT wr.email
            FROM webinar_registrations wr
            WHERE wr.webinar_id = ?
            AND wr.status = 'registered'
        )
        ORDER BY u.id
    ");
    $userStmt->execute([$webinarId]);
}
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);

if ($totalUsers === 0) {
    die("Пользователи для рассылки не найдены\n");
}

echo "Пользователей для приглашения: {$totalUsers}\n\n";

// === Подготовка данных вебинара для шаблона ===
$webinarDate = new DateTime($webinar['scheduled_at'], new DateTimeZone('Europe/Moscow'));
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
           'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

$formattedDate = $webinarDate->format('j') . ' ' . $months[(int)$webinarDate->format('n') - 1] . ' ' . $webinarDate->format('Y');
$formattedTime = $webinarDate->format('H:i');
$dayOfWeek = $days[(int)$webinarDate->format('w')];

// === Отправка писем ===
echo "=== Отправка приглашений ===\n\n";

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($users as $index => $user) {
    $userId = $user['id'];
    $email = $user['email'];
    $fullName = $user['full_name'];

    // Пропускаем невалидные email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "[SKIP] {$fullName} <{$email}> — невалидный email\n";
        $skipped++;
        continue;
    }

    // Проверяем отписку (кроме тестового режима)
    if (!$testEmail && isset($unsubscribed[strtolower($email)])) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    // Прогресс каждые 50 писем
    if (($index + 1) % 50 === 0 || $index === 0) {
        $pct = round(($index + 1) / $totalUsers * 100, 1);
        echo "--- Прогресс: " . ($index + 1) . " / {$totalUsers} ({$pct}%) ---\n";
    }

    if ($dryRun) {
        echo "[DRY] {$fullName} <{$email}>\n";
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

        // Unsubscribe
        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        // Переменные шаблона
        $user_name = $fullName;
        $webinar_title = $webinar['title'];
        $webinar_slug = $webinar['slug'];
        $webinar_description = $webinar['short_description'] ?? '';
        $webinar_datetime_full = "{$formattedDate}, {$dayOfWeek}, в {$formattedTime} МСК";
        $webinar_duration = $webinar['duration_minutes'] ?? 60;
        $speaker_name = $webinar['speaker_name'] ?? '';
        $speaker_position = $webinar['speaker_position'] ?? '';
        $speaker_photo = '';
        if (!empty($webinar['speaker_photo'])) {
            $speaker_photo = str_starts_with($webinar['speaker_photo'], '/')
                ? SITE_URL . $webinar['speaker_photo']
                : SITE_URL . '/uploads/speakers/' . $webinar['speaker_photo'];
        }
        $certificate_price = $webinar['certificate_price'] ?? 200;
        $certificate_hours = $webinar['certificate_hours'] ?? 2;
        $site_url = SITE_URL;

        // Рендер HTML-шаблона
        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_invitation.php';
        $htmlBody = ob_get_clean();

        // UTM для текстовой версии
        $utm = 'utm_source=email&utm_medium=invite&utm_campaign=webinar-chitatelskie-marafony';
        $webinarLink = SITE_URL . '/vebinar/' . $webinar['slug'] . '?' . $utm;

        // Plain text версия
        $textBody = "Здравствуйте, {$fullName}!\n\n";
        $textBody .= "Приглашаем вас на бесплатный вебинар для педагогов!\n\n";
        $textBody .= "«{$webinar['title']}»\n";
        $textBody .= "Дата: {$webinar_datetime_full}\n";
        $textBody .= "Продолжительность: {$webinar_duration} минут\n";
        if ($speaker_name) {
            $textBody .= "Спикер: {$speaker_name}\n";
        }
        $textBody .= "\nРегистрация бесплатная: {$webinarLink}\n\n";
        $textBody .= "После вебинара можно получить именной сертификат на {$certificate_hours} часа ({$certificate_price} руб.)\n\n";
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

        echo "[SENT] {$fullName} <{$email}>\n";
        $sent++;

        // Пауза 500мс между письмами
        usleep(500000);

    } catch (Exception $e) {
        echo "[FAIL] {$fullName} <{$email}> — " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ============================================
// РЕЗУЛЬТАТ
// ============================================
echo "\n=== Результат ===\n";
echo "Всего пользователей: {$totalUsers}\n";
echo "Отправлено: {$sent}\n";
echo "Пропущено: {$skipped}\n";
echo "Ошибки: {$failed}\n";

// Логирование
$mode = $testEmail ? "TEST({$testEmail})" : ($dryRun ? "DRY_RUN" : "SEND");
$logMessage = "[" . date('Y-m-d H:i:s') . "] WEBINAR_INVITATION | Mode: {$mode} | Webinar: {$webinar['title']} | Total: {$totalUsers}, Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}\n";
$logFile = BASE_PATH . '/logs/webinar-email-journey.log';
$logDir = dirname($logFile);
if (!file_exists($logDir)) {
    mkdir($logDir, 0755, true);
}
error_log($logMessage, 3, $logFile);

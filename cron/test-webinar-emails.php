#!/usr/bin/env php
<?php
/**
 * Test: send all webinar email templates to a specified email
 */
if (php_sapi_name() !== 'cli') die('CLI only');

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

// Get webinar data from DB
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
$stmt = $pdo->prepare('SELECT w.*, s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo FROM webinars w LEFT JOIN speakers s ON w.speaker_id = s.id WHERE w.id = 10');
$stmt->execute();
$webinar = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$webinar) die('Webinar not found');

// Build template data
$webinarDate = new DateTime($webinar['scheduled_at'], new DateTimeZone('Europe/Moscow'));
$months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
$days = ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'];
$formattedDate = $webinarDate->format('j') . ' ' . $months[(int)$webinarDate->format('n') - 1] . ' ' . $webinarDate->format('Y');
$formattedTime = $webinarDate->format('H:i');
$dayOfWeek = $days[(int)$webinarDate->format('w')];

// Google Calendar URL
$startUtc = (clone $webinarDate)->setTimezone(new DateTimeZone('UTC'));
$duration = (int)($webinar['duration_minutes'] ?? 60);
$endUtc = (clone $startUtc)->modify("+{$duration} minutes");
$gcDates = $startUtc->format('Ymd\THis\Z') . '/' . $endUtc->format('Ymd\THis\Z');
$gcDetails = 'Вебинар на ФГОС-Практикум. Страница: ' . SITE_URL . '/vebinar/' . $webinar['slug'];
$broadcastUrl = $webinar['broadcast_url'] ?? '';
if ($broadcastUrl) {
    $gcDetails .= "\n\nСсылка на вебинарную комнату: " . $broadcastUrl;
}
$googleCalendarUrl = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
    . '&text=' . rawurlencode($webinar['title'])
    . '&dates=' . $gcDates
    . '&details=' . rawurlencode($gcDetails)
    . ($broadcastUrl ? '&location=' . rawurlencode($broadcastUrl) : '');

$speakerPhoto = '';
if ($webinar['speaker_photo']) {
    $speakerPhoto = str_starts_with($webinar['speaker_photo'], '/') 
        ? SITE_URL . $webinar['speaker_photo'] 
        : SITE_URL . '/uploads/speakers/' . $webinar['speaker_photo'];
}

$templateData = [
    'user_name' => 'Тестовый Пользователь',
    'user_first_name' => 'Тестовый',
    'user_email' => $testEmail,
    'webinar_id' => 10,
    'webinar_title' => $webinar['title'],
    'webinar_slug' => $webinar['slug'],
    'webinar_date' => $formattedDate,
    'webinar_time' => $formattedTime,
    'webinar_day_of_week' => $dayOfWeek,
    'webinar_datetime_full' => "{$formattedDate}, {$dayOfWeek}, в {$formattedTime} МСК",
    'webinar_duration' => $duration,
    'webinar_description' => $webinar['short_description'] ?? '',
    'broadcast_url' => $broadcastUrl,
    'video_url' => $webinar['video_url'] ?? '',
    'speaker_name' => $webinar['speaker_name'] ?? '',
    'speaker_position' => $webinar['speaker_position'] ?? '',
    'speaker_photo' => $speakerPhoto,
    'certificate_price' => $webinar['certificate_price'] ?? 200,
    'certificate_hours' => $webinar['certificate_hours'] ?? 2,
    'registration_id' => 0,
    'calendar_url' => SITE_URL . '/ajax/generate-ics.php?registration_id=0',
    'google_calendar_url' => $googleCalendarUrl,
    'webinar_url' => SITE_URL . '/vebinar/' . $webinar['slug'],
    'cabinet_url' => SITE_URL . '/pages/cabinet.php?tab=webinars',
    'certificate_url' => SITE_URL . '/pages/cabinet.php',
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=test',
    'site_url' => SITE_URL,
    'site_name' => SITE_NAME ?? 'ФГОС-Практикум',
    'touchpoint_code' => '',
];

$templates = [
    'webinar_confirmation' => 'Подтверждение регистрации',
    'webinar_reminder_24h' => 'Напоминание за 24 часа',
    'webinar_broadcast_link' => 'Ссылка на трансляцию (за 1 час)',
    'webinar_reminder_15min' => 'Напоминание за 15 минут',
];

echo "Отправка тестовых писем вебинара на: {$testEmail}\n";
echo "Вебинар: {$webinar['title']}\n";
echo "broadcast_url: {$broadcastUrl}\n\n";

foreach ($templates as $tpl => $desc) {
    echo "Отправка: {$desc}... ";
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
            if (SMTP_PORT == 465) $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            elseif (SMTP_PORT == 587) $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPAuth = false;
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($testEmail);

        extract($templateData);
        $touchpoint_code = $tpl;
        ob_start();
        include BASE_PATH . '/includes/email-templates/' . $tpl . '.php';
        $htmlBody = ob_get_clean();

        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader('[ТЕСТ] ' . $desc . ': ' . $webinar['title'], 'UTF-8', 'B');
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);
        $mail->send();
        echo "OK\n";
        sleep(2);
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }
}
echo "\nГотово! Проверьте почту {$testEmail}\n";

#!/usr/bin/env php
<?php
/**
 * Тестовая отправка одного follow-up письма
 * Использование: php test-followup-single.php [email]
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

// Получаем данные реального вебинара
$stmt = $db->query("SELECT w.*, s.full_name as speaker_name, s.position as speaker_position, s.photo as speaker_photo
    FROM webinars w LEFT JOIN speakers s ON w.speaker_id = s.id
    WHERE w.slug = 'vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony' LIMIT 1");
$webinar = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$webinar) {
    die("Вебинар не найден!\n");
}

echo "Вебинар: {$webinar['title']}\n";
echo "Отправка follow-up на: {$testEmail}\n\n";

// Подготавливаем данные шаблона (как в WebinarEmailJourney::prepareTemplateData)
$webinarDate = new DateTime($webinar['scheduled_at'], new DateTimeZone('Europe/Moscow'));
$months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
           'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
$days = ['воскресенье', 'понедельник', 'вторник', 'среда', 'четверг', 'пятница', 'суббота'];

$formattedDate = $webinarDate->format('j') . ' ' . $months[(int)$webinarDate->format('n') - 1] . ' ' . $webinarDate->format('Y');
$formattedTime = $webinarDate->format('H:i');
$dayOfWeek = $days[(int)$webinarDate->format('w')];

// Переменные для шаблона
$site_url = SITE_URL;
$user_name = 'Тестовый Пользователь';
$user_first_name = 'Тестовый';
$user_email = $testEmail;
$webinar_id = $webinar['id'];
$webinar_title = $webinar['title'];
$webinar_slug = $webinar['slug'];
$webinar_date = $formattedDate;
$webinar_time = $formattedTime;
$webinar_day_of_week = $dayOfWeek;
$webinar_datetime_full = "{$formattedDate}, {$dayOfWeek}, в {$formattedTime} МСК";
$webinar_duration = $webinar['duration_minutes'] ?? 90;
$webinar_description = $webinar['short_description'] ?? '';
$broadcast_url = $webinar['broadcast_url'] ?? '';
$video_url = $webinar['video_url'] ?? '';
$speaker_name = $webinar['speaker_name'] ?? '';
$speaker_position = $webinar['speaker_position'] ?? '';
$speaker_photo = $webinar['speaker_photo'] ? (str_starts_with($webinar['speaker_photo'], '/') ? SITE_URL . $webinar['speaker_photo'] : SITE_URL . '/uploads/speakers/' . $webinar['speaker_photo']) : '';
$certificate_price = $webinar['certificate_price'] ?? 200;
$certificate_hours = $webinar['certificate_hours'] ?? 2;
$registration_id = 0;
$webinar_url = SITE_URL . '/vebinar/' . $webinar_slug;
$cabinet_url = SITE_URL . '/pages/cabinet.php?tab=webinars';
$certificate_url = SITE_URL . '/pages/webinar-certificate.php?registration_id=0';
$unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=test';
$site_name = 'ФГОС-Практикум';
$touchpoint_code = 'webinar_followup';

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
    $mail->addAddress($testEmail);

    // Рендер шаблона
    ob_start();
    include BASE_PATH . '/includes/email-templates/webinar_followup.php';
    $htmlBody = ob_get_clean();

    $mail->isHTML(true);
    $mail->Subject = mb_encode_mimeheader('[ТЕСТ] Спасибо за участие в вебинаре! Запись и сертификат', 'UTF-8', 'B');
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));

    $mail->send();
    echo "OK — письмо отправлено!\n";

} catch (Exception $e) {
    echo "ОШИБКА: " . $e->getMessage() . "\n";
}

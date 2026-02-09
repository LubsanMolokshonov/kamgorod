<?php
/**
 * Test script to send webinar email templates
 */

if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

$templates = [
    'webinar_confirmation' => 'Подтверждение регистрации',
    'webinar_reminder_24h' => 'Напоминание за 24 часа',
    'webinar_broadcast_link' => 'Ссылка на трансляцию (за 1 час)',
    'webinar_followup' => 'После вебинара (follow-up)',
];

// Test data
$site_url = SITE_URL;
$user_name = 'Тестовый Пользователь';
$webinar_title = 'Разговоры о важном без зевоты';
$webinar_date = '6 февраля 2026';
$webinar_time = '14:00';
$webinar_duration = 60;
$speaker_name = 'Иванова Мария Петровна';
$speaker_position = 'к.п.н., методист';
$speaker_photo = '';
$broadcast_url = 'https://start.bizon365.ru/room/32592/zevaut';
$video_url = 'https://fgos.pro/video/test';
$certificate_url = SITE_URL . '/pages/webinar-certificate.php?registration_id=1';
$cabinet_url = SITE_URL . '/pages/cabinet.php?tab=webinars';
$calendar_url = SITE_URL . '/ajax/generate-ics.php?registration_id=1';
$webinar_url = SITE_URL . '/vebinar/test';
$unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=test';
$certificate_price = 149;
$certificate_hours = 2;

echo "Отправка тестовых писем вебинара на: {$testEmail}\n\n";

foreach ($templates as $template => $description) {
    echo "Отправка: {$description}... ";

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

        // Render template
        ob_start();
        include BASE_PATH . '/includes/email-templates/' . $template . '.php';
        $htmlBody = ob_get_clean();

        $mail->isHTML(true);
        $mail->Subject = '[ТЕСТ] ' . $description . ': ' . $webinar_title;
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

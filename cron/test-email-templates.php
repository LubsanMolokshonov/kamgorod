#!/usr/bin/env php
<?php
/**
 * Test script to send all email journey templates to a specified email
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
    'journey_touch_1h' => 'Касание 1: Мягкое напоминание (1 час)',
    'journey_touch_24h' => 'Касание 2: Преимущества (24 часа)',
    'journey_touch_3d' => 'Касание 3: FOMO (3 дня)',
    'journey_touch_7d' => 'Касание 4: Последний шанс (7 дней)',
];

$subjects = [
    'journey_touch_1h' => 'Вы почти завершили регистрацию на конкурс!',
    'journey_touch_24h' => 'Не упустите возможность получить диплом!',
    'journey_touch_3d' => 'Время участия ограничено - успейте оплатить!',
    'journey_touch_7d' => 'Последний шанс принять участие в конкурсе!',
];

// Test data
$templateData = [
    'user_name' => 'Тестовый Пользователь',
    'user_email' => $testEmail,
    'competition_title' => 'Всероссийский конкурс "Лучший педагог 2026"',
    'competition_price' => 350,
    'nomination' => 'Лучшая методическая разработка',
    'work_title' => 'Инновационные методы обучения',
    'payment_url' => SITE_URL . '/pages/cart.php',
    'competition_url' => SITE_URL . '/pages/competition-detail.php?slug=test',
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=test123',
    'site_url' => SITE_URL,
    'site_name' => SITE_NAME ?? 'Каменный город',
];

echo "Отправка тестовых писем на: {$testEmail}\n\n";

foreach ($templates as $template => $description) {
    echo "Отправка: {$description}... ";

    try {
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        // Configure auth based on settings
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
        extract($templateData);
        ob_start();
        include BASE_PATH . '/includes/email-templates/' . $template . '.php';
        $htmlBody = ob_get_clean();

        $mail->isHTML(true);
        $mail->Subject = '[ТЕСТ] ' . $subjects[$template];
        $mail->Body = $htmlBody;
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        echo "OK\n";

        // Small delay between emails
        sleep(2);

    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }
}

echo "\nГотово! Проверьте почту {$testEmail}\n";

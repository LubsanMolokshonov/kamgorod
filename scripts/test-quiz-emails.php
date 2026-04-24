#!/usr/bin/env php
<?php
/**
 * Тестовая отправка 5 quiz-шаблонов олимпиад
 * Usage: php scripts/test-quiz-emails.php email@example.com
 */
if (php_sapi_name() !== 'cli') die('CLI only');

set_time_limit(60);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

$templates = [
    ['name' => 'olympiad_reg_welcome',                'subject' => '[ТЕСТ] Добро пожаловать на олимпиаду!'],
    ['name' => 'olympiad_reg_reminder_1h',            'subject' => '[ТЕСТ] Олимпиада ждёт вас!'],
    ['name' => 'olympiad_quiz_success',               'subject' => '[ТЕСТ] Поздравляем! 1 место!'],
    ['name' => 'olympiad_quiz_success_reminder_24h',  'subject' => '[ТЕСТ] Ваш результат ждёт оформления'],
    ['name' => 'olympiad_quiz_fail',                  'subject' => '[ТЕСТ] Спасибо за участие в олимпиаде!'],
];

$templateData = [
    'user_name'      => 'Лубсан',
    'olympiad_title' => 'Всероссийская олимпиада по математике для педагогов',
    'olympiad_url'   => SITE_URL . '/olimpiady/vserossijskaya-olimpiada-po-matematike/',
    'site_url'       => SITE_URL,
    'site_name'      => SITE_NAME ?? 'Каменный город',
    'score'          => 9,
    'placement'      => '1',
    'placement_text' => '1 место',
    'olympiad_price' => 169,
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=test',
];

$sent = 0;
foreach ($templates as $tpl) {
    echo "Отправка {$tpl['name']}... ";

    // Override score for fail template
    $data = $templateData;
    if ($tpl['name'] === 'olympiad_quiz_fail') {
        $data['score'] = 5;
    }

    extract($data);
    ob_start();
    include BASE_PATH . '/includes/email-templates/' . $tpl['name'] . '.php';
    $htmlBody = ob_get_clean();

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
        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader($tpl['subject'], 'UTF-8', 'B');
        $mail->Body = $htmlBody;
        $mail->AltBody = 'Тестовое письмо: ' . $tpl['name'];
        $mail->send();

        echo "OK\n";
        $sent++;
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }

    sleep(1);
}

echo "\nГотово: отправлено {$sent} из " . count($templates) . "\n";

<?php
/**
 * Тестовая отправка всех 10 plain-text шаблонов олимпиад на один адрес.
 * Usage: php scripts/test-olympiad-emails.php email@example.com
 */
if (php_sapi_name() !== 'cli') die('CLI only');

set_time_limit(120);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$testEmail = $argv[1] ?? null;
if (!$testEmail) {
    die("Usage: php test-olympiad-emails.php email@example.com\n");
}

$templates = [
    ['name' => 'olympiad_reg_welcome',              'subject' => '[ТЕСТ] Добро пожаловать на олимпиаду!'],
    ['name' => 'olympiad_reg_reminder_1h',          'subject' => '[ТЕСТ] Олимпиада ждёт вас — начните тест!'],
    ['name' => 'olympiad_quiz_success',             'subject' => '[ТЕСТ] Поздравляем с 1 местом! Ваш диплом готов'],
    ['name' => 'olympiad_quiz_fail',                'subject' => '[ТЕСТ] Спасибо за участие в олимпиаде!'],
    ['name' => 'olympiad_quiz_success_reminder_24h','subject' => '[ТЕСТ] Ваш диплом за 1 место ждёт оформления'],
    ['name' => 'olympiad_pay_1h',                   'subject' => '[ТЕСТ] 1ч — Заберите свой диплом олимпиады'],
    ['name' => 'olympiad_pay_24h',                  'subject' => '[ТЕСТ] 24ч — Ваш диплом олимпиады ждёт вас'],
    ['name' => 'olympiad_pay_3d',                   'subject' => '[ТЕСТ] 3д — Не упустите свой диплом олимпиады'],
    ['name' => 'olympiad_pay_7d',                   'subject' => '[ТЕСТ] 7д — Последний шанс получить диплом'],
    ['name' => 'olympiad_pay_14d',                  'subject' => '[ТЕСТ] 14д — Персональная скидка 15% на диплом'],
];

$templateData = [
    'user_name' => 'Лубсан',
    'user_email' => $testEmail,
    'user_id' => 1,
    'olympiad_title' => 'Всероссийская олимпиада по математике для педагогов',
    'olympiad_slug' => 'vserossijskaya-olimpiada-po-matematike',
    'olympiad_price' => 169,
    'score' => 9,
    'placement' => '1',
    'placement_text' => '1 место',
    'has_supervisor' => true,
    'supervisor_name' => 'Иванова Мария Петровна',
    'payment_url' => SITE_URL . '/korzina/',
    'olympiad_url' => SITE_URL . '/olimpiady/vserossijskaya-olimpiada-po-matematike/',
    'diploma_url' => SITE_URL . '/olimpiada-diplom/test-12345',
    'result_id' => 'test-12345',
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=test',
    'site_url' => SITE_URL,
    'site_name' => 'ФГОС-Практикум',
    'touchpoint_code' => 'test',
    'footer_reason' => 'прошли олимпиаду на нашем портале',
    'discount_rate' => 0.15,
    'discount_hours' => 48,
];

$sent = 0;
foreach ($templates as $tpl) {
    echo "Отправка {$tpl['name']}... ";

    extract($templateData);
    ob_start();
    include BASE_PATH . '/includes/email-templates/' . $tpl['name'] . '.php';
    $textBody = ob_get_clean();

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
        $mail->addAddress($testEmail, 'Лубсан');
        $mail->isHTML(false);
        $mail->Subject = mb_encode_mimeheader($tpl['subject'], 'UTF-8', 'B');
        $mail->Body = $textBody;
        $mail->send();

        echo "OK\n";
        $sent++;
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }

    sleep(2);
}

echo "\nОтправлено: {$sent}/" . count($templates) . "\n";

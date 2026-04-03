<?php
/**
 * Тестовая отправка 4 олимпиадных email-шаблонов
 * Usage: php scripts/test-olympiad-emails.php email@example.com
 */
if (php_sapi_name() !== 'cli') die('CLI only');

set_time_limit(60);
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
    ['name' => 'olympiad_pay_1h',  'subject' => '[ТЕСТ] 1ч — Вы прошли олимпиаду! Заберите свой диплом'],
    ['name' => 'olympiad_pay_24h', 'subject' => '[ТЕСТ] 24ч — Ваш диплом олимпиады ждёт вас!'],
    ['name' => 'olympiad_pay_3d',  'subject' => '[ТЕСТ] 3д — Не упустите свой диплом олимпиады!'],
    ['name' => 'olympiad_pay_7d',  'subject' => '[ТЕСТ] 7д — Последний шанс получить диплом олимпиады'],
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
    'unsubscribe_url' => SITE_URL . '/pages/unsubscribe.php?token=test',
    'site_url' => SITE_URL,
    'site_name' => SITE_NAME ?? 'Каменный город',
    'touchpoint_code' => 'test',
    'footer_reason' => 'прошли олимпиаду на нашем портале',
];

$sent = 0;
foreach ($templates as $tpl) {
    echo "Отправка {$tpl['name']}... ";

    // Render template
    extract($templateData);
    ob_start();
    include BASE_PATH . '/includes/email-templates/' . $tpl['name'] . '.php';
    $htmlBody = ob_get_clean();

    // Send
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
        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader($tpl['subject'], 'UTF-8', 'B');
        $mail->Body = $htmlBody;
        $mail->AltBody = 'Тестовое письмо олимпиадной цепочки: ' . $tpl['name'];
        $mail->send();

        echo "OK\n";
        $sent++;
    } catch (Exception $e) {
        echo "ОШИБКА: " . $e->getMessage() . "\n";
    }

    sleep(1);
}

echo "\nОтправлено: {$sent}/" . count($templates) . "\n";

#!/usr/bin/env php
<?php
/**
 * Test Script: Send all publication email templates to a test address
 * Usage: php scripts/test_publication_emails.php [email]
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

echo "Sending all 8 publication email templates to: {$testEmail}\n\n";

// Sample data for templates
$sampleData = [
    'user_name'          => 'Иван Петров',
    'user_email'         => $testEmail,
    'user_id'            => 1,
    'publication_title'  => 'Методические рекомендации по использованию ИКТ в начальной школе',
    'publication_slug'   => 'metodicheskie-rekomendatsii-ikt',
    'certificate_price'  => 299,
    'certificate_url'    => SITE_URL . '/pages/publication-certificate.php?id=1',
    'cabinet_url'        => SITE_URL . '/pages/cabinet.php?tab=publications',
    'submit_url'         => SITE_URL . '/opublikovat',
    'publication_url'    => SITE_URL . '/zhurnal/metodicheskie-rekomendatsii-ikt',
    'moderation_comment' => 'Материал не соответствует педагогической тематике: содержание относится к кулинарии, а не к образованию.',
    'unsubscribe_url'    => SITE_URL . '/pages/unsubscribe.php?token=test',
    'site_url'           => SITE_URL,
    'site_name'          => SITE_NAME ?? 'Каменный город',
    'touchpoint_code'    => '',
];

// All 8 templates to send
$templates = [
    ['file' => 'publication_cert_2h',       'subject' => 'Ваша публикация размещена! Оформите свидетельство',       'delay' => '2 часа'],
    ['file' => 'publication_cert_24h',      'subject' => 'Напоминание: оформите свидетельство о публикации',        'delay' => '24 часа'],
    ['file' => 'publication_cert_3d',       'subject' => 'Акция «2+1» — не упустите выгоду!',                       'delay' => '3 дня'],
    ['file' => 'publication_cert_7d',       'subject' => 'Последний шанс: свидетельство о публикации',              'delay' => '7 дней'],
    ['file' => 'publication_pay_1h',        'subject' => 'Завершите оплату свидетельства — 299 ₽',                  'delay' => '1 час'],
    ['file' => 'publication_pay_24h',       'subject' => 'Ваше свидетельство ожидает оплаты',                       'delay' => '24 часа'],
    ['file' => 'publication_pay_3d',        'subject' => 'Не упустите: акция «2+1» скоро завершится!',              'delay' => '3 дня'],
    ['file' => 'publication_rejected_24h',  'subject' => 'Попробуйте опубликовать снова!',                          'delay' => '24 часа'],
];

$sent = 0;
$failed = 0;

foreach ($templates as $i => $tpl) {
    $num = $i + 1;
    echo "[{$num}/8] {$tpl['file']} ({$tpl['delay']})... ";

    try {
        // Render template
        $data = $sampleData;
        $data['touchpoint_code'] = str_replace('publication_', 'pub_', $tpl['file']);
        extract($data);

        $templatePath = BASE_PATH . '/includes/email-templates/' . $tpl['file'] . '.php';
        if (!file_exists($templatePath)) {
            echo "SKIP (template not found)\n";
            $failed++;
            continue;
        }

        ob_start();
        include $templatePath;
        $htmlBody = ob_get_clean();

        // Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 15;

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
        $mail->addAddress($testEmail, 'Тест');

        $mail->isHTML(true);
        $mail->Subject = "[ТЕСТ {$num}/8] " . $tpl['subject'];
        $mail->Body = $htmlBody;

        $mail->send();
        echo "OK\n";
        $sent++;

        // Small delay between sends
        if ($i < count($templates) - 1) {
            usleep(500000); // 0.5s
        }

    } catch (Exception $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\nDone! Sent: {$sent}, Failed: {$failed}\n";
echo "Check inbox: {$testEmail}\n";

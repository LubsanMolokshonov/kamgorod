#!/usr/bin/env php
<?php
/**
 * Тест: отправка всех курсовых писем (plain-text) на тестовый адрес.
 * Usage: php scripts/test_course_emails.php [email]
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/email-helper.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/classes/CoursePromoEmailCampaign.php';

use PHPMailer\PHPMailer\PHPMailer;

$testEmail = $argv[1] ?? 'lubsanmolokshonov@gmail.com';
$testName  = 'Тестовый Получатель';

echo "Отправка plain-text курсовых писем на: {$testEmail}\n\n";

$baseData = [
    'user_name'           => $testName,
    'user_email'          => $testEmail,
    'user_id'             => 0,
    'course_title'        => 'Современные технологии преподавания в условиях ФГОС',
    'course_price'        => 4900,
    'course_hours'        => 144,
    'course_program_type' => 'kpk',
    'program_label'       => 'Повышение квалификации',
    'document_label'      => 'Удостоверение о повышении квалификации',
    'course_url'          => SITE_URL . '/kursy/test-course/',
    'payment_url'         => SITE_URL . '/kabinet/?tab=courses&test=1',
    'discount_url'        => SITE_URL . '/kabinet/?tab=courses&discount_token=TEST',
    'discount_price'      => 4410,
    'unsubscribe_url'     => SITE_URL . '/pages/unsubscribe.php?token=test',
    'cabinet_url'         => SITE_URL . '/kabinet/?tab=courses',
    'order_number'        => 'TEST-' . date('YmdHis'),
    'payment_amount'      => 4900,
    'site_url'            => SITE_URL,
    'site_name'           => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
    'footer_reason'       => 'тестовая отправка',
];

// Доступ к приватным renderTextTemplate / renderTextVersion через Reflection
$chain      = new CourseEmailChain($db);
$promo      = new CoursePromoEmailCampaign($db);
$rChain     = new ReflectionMethod($chain, 'renderTextTemplate');
$rChain->setAccessible(true);
$rPromo     = new ReflectionMethod($promo, 'renderTextVersion');
$rPromo->setAccessible(true);

$cases = [
    ['course_enroll_welcome',   '[ТЕСТ] Заявка на курс принята'],
    ['course_enroll_15min',     '[ТЕСТ] Ваше место на курсе забронировано'],
    ['course_enroll_1h',        '[ТЕСТ] Не откладывайте профессиональный рост'],
    ['course_enroll_24h',       '[ТЕСТ] Скидка 10% на курс — 48 часов'],
    ['course_enroll_2d',        '[ТЕСТ] Скидка 10% ещё действует'],
    ['course_enroll_3d',        '[ТЕСТ] Последний день скидки 10%'],
    ['course_payment_success',  '[ТЕСТ] Оплата курса подтверждена'],
];

$sent = 0; $failed = 0;
foreach ($cases as $i => [$tpl, $subject]) {
    $num = $i + 1;
    echo "[{$num}/8] {$tpl} ... ";
    try {
        $body = $rChain->invoke($chain, $baseData, $tpl);

        $mail = new PHPMailer(true);
        configureBulkMailer($mail, $testEmail);
        $mail->addAddress($testEmail, $testName);
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
        $mail->Body    = $body;
        $mail->addCustomHeader('List-Unsubscribe', '<' . $baseData['unsubscribe_url'] . '>');
        $mail->send();

        echo "OK\n";
        $sent++;
    } catch (\Throwable $e) {
        echo "FAIL: " . $e->getMessage() . "\n";
        $failed++;
    }
    sleep(2);
}

// course_promo
echo "[8/8] course_promo ... ";
try {
    $promoData = $baseData + ['course_description' => 'Программа охватывает актуальные требования ФГОС, методику работы с цифровыми инструментами и подготовку к аттестации.'];
    $body = $rPromo->invoke($promo, $promoData);

    $mail = new PHPMailer(true);
    configureBulkMailer($mail, $testEmail);
    $mail->addAddress($testEmail, $testName);
    $mail->isHTML(false);
    $mail->CharSet = 'UTF-8';
    $mail->Subject = mb_encode_mimeheader('[ТЕСТ] Повышение квалификации: ' . $baseData['course_title'], 'UTF-8', 'B');
    $mail->Body    = $body;
    $mail->addCustomHeader('List-Unsubscribe', '<' . $baseData['unsubscribe_url'] . '>');
    $mail->send();

    echo "OK\n";
    $sent++;
} catch (\Throwable $e) {
    echo "FAIL: " . $e->getMessage() . "\n";
    $failed++;
}

echo "\nИтого: отправлено {$sent}, ошибок {$failed}\n";

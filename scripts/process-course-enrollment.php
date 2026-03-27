<?php
/**
 * Фоновая обработка записи на курс: Bitrix24 CRM + email админу.
 * Запускается из ajax/course-enrollment.php через exec().
 *
 * Аргументы: enrollment_id
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

if (!isset($argv[1]) || !is_numeric($argv[1])) {
    exit('Usage: php process-course-enrollment.php <enrollment_id>');
}

$enrollmentId = intval($argv[1]);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';

$dbObj = new Database($db);

// Получаем запись
$enrollment = $dbObj->queryOne("SELECT * FROM course_enrollments WHERE id = ?", [$enrollmentId]);
if (!$enrollment) {
    error_log("process-course-enrollment: enrollment #{$enrollmentId} not found");
    exit(1);
}

$courseObj = new Course($db);
$course = $courseObj->getById($enrollment['course_id']);
if (!$course) {
    error_log("process-course-enrollment: course #{$enrollment['course_id']} not found");
    exit(1);
}

$fullName = $enrollment['full_name'];
$email = $enrollment['email'];
$phone = $enrollment['phone'];

// Bitrix24 CRM — отложенная синхронизация (10 мин), см. cron/process-course-bitrix.php

// Email админу
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/email-helper.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    configureMailer($mail);

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

    $mail->isHTML(true);
    $mail->Subject = mb_encode_mimeheader(
        'Новая заявка на курс: ' . mb_substr($course['title'], 0, 60),
        'UTF-8', 'B'
    );

    $programLabel = Course::getProgramTypeLabel($course['program_type']);
    $price = number_format($course['price'], 0, ',', ' ');
    $crmStatus = "Отложенная синхронизация (10 мин)";

    $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
        <h2 style="margin: 0;">Новая заявка на курс</h2>
    </div>
    <div style="background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px;">
        <h3 style="color: #667eea; margin-top: 0;">{$fullName}</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <tr><td style="padding: 6px 0; color: #666;">Email:</td><td style="padding: 6px 0;"><strong>{$email}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Телефон:</td><td style="padding: 6px 0;"><strong>{$phone}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Курс:</td><td style="padding: 6px 0;"><strong>{$course['title']}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Тип:</td><td style="padding: 6px 0;">{$programLabel}</td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Часы:</td><td style="padding: 6px 0;">{$course['hours']} ч.</td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Стоимость:</td><td style="padding: 6px 0;">{$price} ₽</td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Bitrix24:</td><td style="padding: 6px 0;">{$crmStatus}</td></tr>
        </table>
    </div>
</div>
</body>
</html>
HTML;

    $mail->AltBody = "Новая заявка на курс\n\nИмя: {$fullName}\nEmail: {$email}\nТелефон: {$phone}\nКурс: {$course['title']}\nТип: {$programLabel}\nСтоимость: {$price} руб.\nBitrix24: {$crmStatus}";

    $mail->send();
} catch (Exception $e) {
    error_log('Course enrollment admin email error: ' . $e->getMessage());
}

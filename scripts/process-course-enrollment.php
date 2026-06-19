<?php
/**
 * Фоновая обработка записи на курс: email-цепочка дожима + Bitrix24 CRM + email админу.
 * Запускается из ajax/course-enrollment.php через exec().
 *
 * Порядок намеренный: сначала планируем цепочку (критично для бизнеса),
 * потом Bitrix и письмо админу (некритично). Каждый блок изолирован
 * catch(\Throwable) — падение одного не блокирует остальные.
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
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';
require_once __DIR__ . '/../classes/CourseEmailChain.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

$dbObj = new Database($db);

// Получаем запись
$enrollment = $dbObj->queryOne("SELECT * FROM course_enrollments WHERE id = ?", [$enrollmentId]);
if (!$enrollment) {
    error_log("process-course-enrollment: enrollment #{$enrollmentId} not found");
    exit(1);
}

// ──────────────────────────────────────────────
// 1. Email-цепочка дожима (критично) — планируем ПЕРВОЙ, до Bitrix и письма админу,
//    чтобы сбой некритичных блоков не оставил заявку без писем.
// ──────────────────────────────────────────────
try {
    $emailChain = new CourseEmailChain($db);
    $scheduled = $emailChain->scheduleForEnrollment($enrollmentId);
    if ($scheduled) {
        error_log("process-course-enrollment: scheduled {$scheduled} emails for enrollment #{$enrollmentId}");
    }
} catch (\Throwable $e) {
    error_log('Course email chain schedule error (enrollment #' . $enrollmentId . '): ' . $e->getMessage());
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

// ──────────────────────────────────────────────
// 2. Bitrix24 CRM — создание сделки сразу после записи
// ──────────────────────────────────────────────
$crmStatus = '—';
try {
    $bitrix = new Bitrix24Integration();
    if ($bitrix->isConfigured()) {
        $stageNew = defined('BITRIX24_COURSE_STAGE_NEW') ? BITRIX24_COURSE_STAGE_NEW : 'C108:NEW';
        $abVariant = $enrollment['ab_variant'] ?? 'A';
        $abPrice = CoursePriceAB::getAdjustedPrice(floatval($course['price']), $abVariant, $course['program_type'] ?? null);

        $dealId = $bitrix->createCourseDeal([
            'user_id' => $enrollment['user_id'] ?? null,
            'full_name' => $fullName,
            'email' => $email,
            'phone' => $phone,
            'utm_source' => $enrollment['utm_source'] ?? '',
            'utm_medium' => $enrollment['utm_medium'] ?? '',
            'utm_campaign' => $enrollment['utm_campaign'] ?? '',
            'utm_content' => $enrollment['utm_content'] ?? '',
            'utm_term' => $enrollment['utm_term'] ?? '',
            'ym_uid' => $enrollment['ym_uid'] ?? '',
            'source_page' => $enrollment['source_page'] ?? '',
        ], $course, $stageNew, $abPrice);

        if ($dealId) {
            $dbObj->update('course_enrollments', [
                'bitrix_lead_id' => $dealId,
                'bitrix_stage' => $stageNew,
            ], 'id = ?', [$enrollmentId]);
            $crmStatus = "Сделка #{$dealId}";
        } else {
            $crmStatus = 'Ошибка API (retry через cron)';
            error_log("process-course-enrollment: Bitrix24 API returned null for enrollment #{$enrollmentId}");
        }
    } else {
        $crmStatus = 'Bitrix24 не настроен';
    }
} catch (\Throwable $e) {
    $crmStatus = 'Ошибка (retry через cron)';
    error_log("process-course-enrollment: Bitrix24 error for enrollment #{$enrollmentId}: " . $e->getMessage());
}

// ──────────────────────────────────────────────
// 3. Email админу (некритично) — через Unisender Go (EmailDispatcher)
// ──────────────────────────────────────────────
try {
    $programLabel = Course::getProgramTypeLabel($course['program_type']);
    // Фактическая цена (фиксированная скидка / AB-вариант enrollment)
    $abVariant = $enrollment['ab_variant'] ?? 'A';
    $abPrice = CoursePriceAB::getAdjustedPrice(floatval($course['price']), $abVariant, $course['program_type'] ?? null);
    $price = number_format($abPrice, 0, ',', ' ');

    $fullNameSafe = htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8');
    $emailSafe    = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
    $phoneSafe    = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
    $courseSafe   = htmlspecialchars($course['title'], ENT_QUOTES, 'UTF-8');

    $adminHtml = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
        <h2 style="margin: 0;">Новая заявка на курс</h2>
    </div>
    <div style="background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px;">
        <h3 style="color: #667eea; margin-top: 0;">{$fullNameSafe}</h3>
        <table style="width: 100%; border-collapse: collapse;">
            <tr><td style="padding: 6px 0; color: #666;">Email:</td><td style="padding: 6px 0;"><strong>{$emailSafe}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Телефон:</td><td style="padding: 6px 0;"><strong>{$phoneSafe}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Курс:</td><td style="padding: 6px 0;"><strong>{$courseSafe}</strong></td></tr>
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

    EmailDispatcher::send([
        'to_email'         => SMTP_FROM_EMAIL,
        'to_name'          => SMTP_FROM_NAME,
        'subject'          => 'Новая заявка на курс: ' . mb_substr($course['title'], 0, 60),
        'html'             => $adminHtml,
        'from_name'        => SMTP_FROM_NAME,
        'skip_tracking'    => true,
        'skip_unsubscribe' => true,
        'meta'             => ['email_type' => 'other', 'touchpoint_code' => 'admin_course_enrollment'],
    ]);
} catch (\Throwable $e) {
    error_log('Course enrollment admin email error: ' . $e->getMessage());
}

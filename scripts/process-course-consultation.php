<?php
/**
 * Фоновая обработка заявки на консультацию: Bitrix24 CRM + email админу.
 * Запускается из ajax/course-consultation.php через exec().
 *
 * Аргументы: consultation_id
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

if (!isset($argv[1]) || !is_numeric($argv[1])) {
    exit('Usage: php process-course-consultation.php <consultation_id>');
}

$consultationId = intval($argv[1]);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Bitrix24Integration.php';

$dbObj = new Database($db);

$consultation = $dbObj->queryOne("SELECT * FROM course_consultations WHERE id = ?", [$consultationId]);
if (!$consultation) {
    error_log("process-course-consultation: consultation #{$consultationId} not found");
    exit(1);
}

$phone = $consultation['phone'];
$courseTitle = $consultation['course_title'];

// Bitrix24 CRM
try {
    $bitrix = new Bitrix24Integration();
    if ($bitrix->isConfigured()) {
        $dealId = $bitrix->createCourseConsultationDeal([
            'phone' => $phone,
            'course_title' => $courseTitle,
            'utm_source' => $consultation['utm_source'] ?? '',
            'utm_medium' => $consultation['utm_medium'] ?? '',
            'utm_campaign' => $consultation['utm_campaign'] ?? '',
            'utm_content' => $consultation['utm_content'] ?? '',
            'utm_term' => $consultation['utm_term'] ?? '',
            'ym_uid' => $consultation['ym_uid'] ?? '',
            'source_page' => $consultation['source_page'] ?? '',
        ]);

        if ($dealId) {
            $categoryId = defined('BITRIX24_COURSE_PIPELINE_ID') ? BITRIX24_COURSE_PIPELINE_ID : 108;
            $initialStage = defined('BITRIX24_COURSE_STAGE_NEW') ? BITRIX24_COURSE_STAGE_NEW : ('C' . $categoryId . ':NEW');
            $dbObj->update('course_consultations', [
                'bitrix_lead_id' => $dealId,
                'bitrix_stage' => $initialStage,
                'bitrix_stage_updated_at' => date('Y-m-d H:i:s'),
            ], 'id = ?', [$consultationId]);
        } else {
            $dbObj->execute(
                "UPDATE course_consultations SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
                [$consultationId]
            );
        }
    }
} catch (Exception $e) {
    error_log('Bitrix24 consultation error: ' . $e->getMessage());
    try {
        $dbObj->execute(
            "UPDATE course_consultations SET bitrix_attempts = bitrix_attempts + 1 WHERE id = ?",
            [$consultationId]
        );
    } catch (Exception $ignore) {}
}

// Email админу
try {
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../includes/email-helper.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
    configureMailer($mail);

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress(SMTP_FROM_EMAIL, SMTP_FROM_NAME);

    $mail->isHTML(true);
    $mail->Subject = mb_encode_mimeheader('Заявка на консультацию по курсу', 'UTF-8', 'B');

    $courseLine = $courseTitle ? htmlspecialchars($courseTitle, ENT_QUOTES, 'UTF-8') : '—';
    $phoneSafe = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');

    $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head><meta charset="UTF-8"></head>
<body style="font-family: Arial, sans-serif; line-height: 1.6; color: #333;">
<div style="max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center;">
        <h2 style="margin: 0;">Заявка на консультацию</h2>
    </div>
    <div style="background: #f9f9f9; padding: 20px; border-radius: 0 0 8px 8px;">
        <table style="width: 100%; border-collapse: collapse;">
            <tr><td style="padding: 6px 0; color: #666;">Телефон:</td><td style="padding: 6px 0;"><strong>{$phoneSafe}</strong></td></tr>
            <tr><td style="padding: 6px 0; color: #666;">Курс:</td><td style="padding: 6px 0;">{$courseLine}</td></tr>
        </table>
    </div>
</div>
</body>
</html>
HTML;

    $mail->AltBody = "Заявка на консультацию\nТелефон: {$phone}\nКурс: " . ($courseTitle ?: '—');

    $mail->send();
} catch (Exception $e) {
    error_log('Consultation admin email error: ' . $e->getMessage());
}

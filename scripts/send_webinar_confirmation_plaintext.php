<?php
/**
 * Одноразовый скрипт: plain-text подтверждение регистрации на вебинар.
 * Используется как обход антиспама Яндекса (data not accepted) во время
 * прогрева ящиков info@fgos.pro до 2026-05-11.
 *
 * Запуск:
 *   docker exec pedagogy_web php /var/www/html/scripts/send_webinar_confirmation_plaintext.php <registration_id>
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/email-helper.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$registrationId = (int)($argv[1] ?? 0);
if ($registrationId <= 0) {
    fwrite(STDERR, "Usage: php send_webinar_confirmation_plaintext.php <registration_id>\n");
    exit(1);
}

$dbw = new Database($db);

$reg = $dbw->queryOne(
    "SELECT r.*, w.title AS webinar_title, w.slug AS webinar_slug,
            w.scheduled_at, w.duration_minutes, w.timezone,
            s.full_name AS speaker_name, s.position AS speaker_position
       FROM webinar_registrations r
       JOIN webinars w ON w.id = r.webinar_id
  LEFT JOIN speakers s ON s.id = w.speaker_id
      WHERE r.id = ?",
    [$registrationId]
);

if (!$reg) {
    fwrite(STDERR, "Registration #$registrationId not found\n");
    exit(2);
}

$logRow = $dbw->queryOne(
    "SELECT l.id, l.status FROM webinar_email_log l
       JOIN webinar_email_touchpoints t ON t.id = l.touchpoint_id
      WHERE l.webinar_registration_id = ? AND t.code = 'webinar_confirmation'
   ORDER BY l.id DESC LIMIT 1",
    [$registrationId]
);

$months = [1=>'января',2=>'февраля',3=>'марта',4=>'апреля',5=>'мая',6=>'июня',
           7=>'июля',8=>'августа',9=>'сентября',10=>'октября',11=>'ноября',12=>'декабря'];
$ts = strtotime($reg['scheduled_at']);
$dateHuman = date('j', $ts) . ' ' . $months[(int)date('n', $ts)] . ' ' . date('Y', $ts);
$timeHuman = date('H:i', $ts);
$tz = $reg['timezone'] ?: 'Europe/Moscow';

$cabinetUrl = generateMagicUrl((int)$reg['user_id'], '/kabinet/', 14);
$webinarUrl = SITE_URL . '/vebinar/' . $reg['webinar_slug'] . '/';

$name  = trim((string)($reg['full_name'] ?? ''));
$greet = $name !== '' && $name !== '?' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

$body  = $greet . "\n\n";
$body .= "Вы успешно зарегистрировались на бесплатный вебинар Педагогического портала «Каменный город».\n\n";
$body .= "Тема: {$reg['webinar_title']}\n";
$body .= "Дата и время: {$dateHuman} в {$timeHuman} (МСК, {$tz})\n";
$body .= "Продолжительность: {$reg['duration_minutes']} минут\n";
if (!empty($reg['speaker_name'])) {
    $body .= "Спикер: {$reg['speaker_name']}\n";
}
$body .= "\nСтраница вебинара:\n{$webinarUrl}\n\n";
$body .= "Ссылка на трансляцию придёт отдельным письмом за 1 час до начала. Также её можно будет найти в личном кабинете:\n";
$body .= $cabinetUrl . "\n";
$body .= "(ссылка авторизует вас автоматически и действует 14 дней)\n\n";
$body .= "Если возникнут вопросы — ответьте на это письмо или напишите на info@fgos.pro.\n\n";
$body .= "С уважением,\nКоманда Педагогического портала «Каменный город»\nfgos.pro\n";

$subject = 'Вы зарегистрированы на вебинар: ' . $reg['webinar_title'];

$mail = new PHPMailer(true);
configureMailer($mail);
$mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
$mail->addAddress($reg['email'], $name !== '' ? $name : null);
$mail->isHTML(false);
$mail->CharSet = 'UTF-8';
$mail->Subject = $subject;
$mail->Body = $body;

try {
    $mail->send();
    echo "SENT to {$reg['email']} (registration #{$registrationId})\n";

    if ($logRow) {
        $dbw->execute(
            "UPDATE webinar_email_log
                SET status='sent', sent_at=NOW(), attempts=attempts+1,
                    error_message=CONCAT('plain-text fallback (Yandex warmup) | prev: ', COALESCE(error_message,''))
              WHERE id = ?",
            [$logRow['id']]
        );
        echo "Marked webinar_email_log #{$logRow['id']} as sent\n";
    }

    $logFile = __DIR__ . '/../logs/webinar-email-journey.log';
    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . "] SENT_PLAINTEXT | {$reg['email']} | webinar_confirmation | reg=$registrationId | manual\n",
        FILE_APPEND
    );
} catch (Exception $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . " | ErrorInfo: " . ($mail->ErrorInfo ?? '') . "\n");
    exit(3);
}

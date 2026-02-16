<?php
/**
 * Разовый скрипт: отправка письма-извинения с прикреплённым PDF сертификатом
 * пользователям, оплатившим сертификат вебинара, но не сумевшим его скачать.
 *
 * Запуск:
 *   php scripts/send_apology_download.php --test lubsanmolokshonov@gmail.com   # тест одному
 *   php scripts/send_apology_download.php --dry-run                             # проверка без отправки
 *   php scripts/send_apology_download.php                                       # рассылка всем
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Параметры запуска
$dryRun = in_array('--dry-run', $argv ?? []);
$testEmail = null;
$testIdx = array_search('--test', $argv ?? []);
if ($testIdx !== false && isset($argv[$testIdx + 1])) {
    $testEmail = $argv[$testIdx + 1];
}

$webinarId = 5;

echo "=== Отправка писем-извинений с PDF сертификатами ===\n";
echo "Вебинар ID: {$webinarId}\n";

// Получаем данные получателей
if ($testEmail) {
    // Тестовый режим — ищем по email
    $stmt = $db->prepare("
        SELECT wc.id as cert_id, wc.user_id, wc.full_name, wc.pdf_path, wc.certificate_number, wc.hours as certificate_hours,
               wr.email, wr.id as registration_id,
               w.title as webinar_title
        FROM webinar_certificates wc
        JOIN webinar_registrations wr ON wc.registration_id = wr.id
        JOIN webinars w ON wc.webinar_id = w.id
        WHERE wc.webinar_id = ? AND wc.status = 'ready' AND wr.email = ?
        LIMIT 1
    ");
    $stmt->execute([$webinarId, $testEmail]);
} else {
    // Все оплатившие
    $stmt = $db->prepare("
        SELECT wc.id as cert_id, wc.user_id, wc.full_name, wc.pdf_path, wc.certificate_number, wc.hours as certificate_hours,
               wr.email, wr.id as registration_id,
               w.title as webinar_title
        FROM webinar_certificates wc
        JOIN webinar_registrations wr ON wc.registration_id = wr.id
        JOIN webinars w ON wc.webinar_id = w.id
        WHERE wc.webinar_id = ? AND wc.status = 'ready'
        ORDER BY wc.id
    ");
    $stmt->execute([$webinarId]);
}

$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Получателей: " . count($recipients) . "\n";
echo "Режим: " . ($testEmail ? "ТЕСТ ({$testEmail})" : ($dryRun ? "DRY RUN" : "РАССЫЛКА")) . "\n";
echo "---\n\n";

if (count($recipients) === 0) {
    echo "Нет получателей. Завершаем.\n";
    exit;
}

// Проверяем отписки
$unsubStmt = $db->prepare("SELECT email FROM email_unsubscribes");
$unsubStmt->execute();
$unsubscribed = array_column($unsubStmt->fetchAll(PDO::FETCH_ASSOC), 'email');

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($recipients as $recipient) {
    $email = $recipient['email'];
    $fullName = $recipient['full_name'];
    $certId = $recipient['cert_id'];

    // Проверяем отписку (не для теста)
    if (!$testEmail && in_array($email, $unsubscribed)) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    // Проверяем наличие PDF
    $pdfPath = BASE_PATH . $recipient['pdf_path'];
    if (!file_exists($pdfPath)) {
        echo "[FAIL] {$fullName} <{$email}> — PDF не найден: {$pdfPath}\n";
        $failed++;
        continue;
    }

    if ($dryRun) {
        $pdfSize = round(filesize($pdfPath) / 1024);
        echo "[DRY] {$fullName} <{$email}> (cert #{$certId}, PDF: {$pdfSize}KB)\n";
        $sent++;
        continue;
    }

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
        $mail->addAddress($email, $fullName);

        // Magic-link в личный кабинет
        $userId = $recipient['user_id'];
        $cabinet_url = generateMagicUrl($userId, '/pages/cabinet.php?tab=webinars');
        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        // Переменные для шаблона
        $user_name = $fullName;
        $webinar_title = $recipient['webinar_title'];
        $certificate_hours = $recipient['certificate_hours'] ?? 2;
        $certificate_number = $recipient['certificate_number'];
        $site_url = SITE_URL;

        // Рендер шаблона
        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_apology_download.php';
        $htmlBody = ob_get_clean();

        // Plain text
        $textBody = "Здравствуйте, {$fullName}!\n\n";
        $textBody .= "Вы оплатили сертификат участника вебинара «{$webinar_title}», но из-за технического сбоя скачивание было временно недоступно.\n\n";
        $textBody .= "Приносим искренние извинения! Проблема устранена.\n\n";
        $textBody .= "Ваш сертификат (№ {$certificate_number}, {$certificate_hours} ак. часа) прикреплён к этому письму.\n\n";
        $textBody .= "Личный кабинет: {$cabinet_url}\n\n";
        $textBody .= "Вопросы: info@fgos.pro\n\n";
        $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $textBody .= SITE_URL . "\n\n";
        $textBody .= "Отписаться: {$unsubscribe_url}\n";

        $mail->isHTML(true);
        $mail->Subject = $email_subject; // из шаблона
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Прикрепляем PDF
        $attachmentName = 'sertifikat_' . $certificate_number . '.pdf';
        $mail->addAttachment($pdfPath, $attachmentName);

        // Заголовки отписки
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->send();

        echo "[SENT] {$fullName} <{$email}> (cert #{$certId})\n";
        $sent++;

        // Пауза между письмами
        usleep(200000); // 200ms

    } catch (Exception $e) {
        echo "[FAIL] {$fullName} <{$email}> — " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "\n=== Результат ===\n";
echo "Отправлено: {$sent}\n";
echo "Пропущено: {$skipped}\n";
echo "Ошибки: {$failed}\n";

// Логирование
$mode = $testEmail ? "TEST({$testEmail})" : ($dryRun ? "DRY_RUN" : "SEND");
$logMessage = "[" . date('Y-m-d H:i:s') . "] APOLOGY_DOWNLOAD | Mode: {$mode} | Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}\n";
error_log($logMessage, 3, BASE_PATH . '/logs/webinar-email-journey.log');

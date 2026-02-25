<?php
/**
 * Разовый скрипт: отправка письма-извинения пользователям,
 * которые кликнули на сертификат после вебинара "Разговоры о важном"
 * и столкнулись с технической ошибкой.
 *
 * Запуск: php scripts/send_apology_certificate.php [--dry-run]
 */

// Определяем BASE_PATH
define('BASE_PATH', dirname(__DIR__));

// Загружаем конфиги
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Параметры
$dryRun = in_array('--dry-run', $argv ?? []);
$webinarId = 5; // razgovory-o-vazhnom-bez-zevoty

// 56 registration IDs, которые кликнули на сертификат из follow-up письма
$registrationIds = [
    5, 41, 56, 58, 83, 88, 108, 129, 139, 161, 169, 172, 186, 188, 197, 199,
    201, 210, 213, 217, 226, 227, 231, 232, 247, 256, 281, 287, 291, 326,
    343, 353, 365, 373, 376, 379, 385, 396, 401, 402, 403, 404, 407, 408,
    412, 439, 466, 468, 482, 484, 487, 493, 525, 551, 574, 580
];

echo "=== Отправка писем-извинений (сертификат) ===\n";
echo "Вебинар ID: {$webinarId}\n";
echo "Получателей: " . count($registrationIds) . "\n";
echo "Режим: " . ($dryRun ? "DRY RUN (без отправки)" : "ОТПРАВКА") . "\n";
echo "---\n\n";

// Получаем данные получателей
$placeholders = implode(',', array_fill(0, count($registrationIds), '?'));

$stmt = $db->prepare("
    SELECT wr.id as registration_id, wr.user_id, wr.full_name, wr.email,
           w.title as webinar_title, w.certificate_price, w.certificate_hours
    FROM webinar_registrations wr
    JOIN webinars w ON wr.webinar_id = w.id
    WHERE wr.id IN ({$placeholders})
    ORDER BY wr.full_name
");
$stmt->execute($registrationIds);
$recipients = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Найдено в БД: " . count($recipients) . " получателей\n\n";

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
    $regId = $recipient['registration_id'];

    // Проверяем отписку
    if (in_array($email, $unsubscribed)) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo "[DRY] {$fullName} <{$email}> (reg #{$regId})\n";
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

        // Генерация magic-link и unsubscribe
        $userId = $recipient['user_id'];
        $certificateUrl = generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $regId);
        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        // Подготовка переменных для шаблона
        $user_name = $fullName;
        $webinar_title = $recipient['webinar_title'];
        $certificate_price = $recipient['certificate_price'] ?? 200;
        $certificate_hours = $recipient['certificate_hours'] ?? 2;
        $certificate_url = $certificateUrl;
        $site_url = SITE_URL;
        $unsubscribe_url = $unsubscribeUrl;

        // Рендер шаблона
        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_apology_certificate.php';
        $htmlBody = ob_get_clean();

        // Plain text версия
        $textBody = "Здравствуйте, {$fullName}!\n\n";
        $textBody .= "После вебинара «{$webinar_title}» вы переходили по ссылке для оформления сертификата, но столкнулись с технической ошибкой.\n\n";
        $textBody .= "Приносим искренние извинения! Проблема полностью устранена.\n\n";
        $textBody .= "Оформить сертификат ({$certificate_hours} ак. часа, {$certificate_price} руб.):\n";
        $textBody .= $certificateUrl . "\n\n";
        $textBody .= "Если возникнут вопросы — info@fgos.pro\n\n";
        $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $textBody .= SITE_URL . "\n\n";
        $textBody .= "Отписаться: {$unsubscribeUrl}\n";

        $mail->isHTML(true);
        $mail->Subject = $email_subject; // из шаблона
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Заголовки отписки
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->send();

        echo "[SENT] {$fullName} <{$email}>\n";
        $sent++;

        // Небольшая пауза между письмами
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
$logMessage = "[" . date('Y-m-d H:i:s') . "] APOLOGY_CERTIFICATE | Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}\n";
$logFile = BASE_PATH . '/logs/webinar-email-journey.log';
error_log($logMessage, 3, $logFile);

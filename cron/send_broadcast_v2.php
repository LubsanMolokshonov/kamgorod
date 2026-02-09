<?php
define("BASE_PATH", "/var/www/html");
require_once BASE_PATH . "/config/config.php";
require_once BASE_PATH . "/vendor/autoload.php";

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $testEmail = "e-20040481@yandex.ru";
    $site_url = SITE_URL;
    $user_name = "Uvazhayemiy uchastnik";
    $webinar_title = "Razgovory o vazhnom bez zevoty";
    $webinar_time = "14:00";
    $webinar_duration = 60;
    $speaker_name = "";
    $broadcast_url = "https://start.bizon365.ru/room/32592/zevaut";
    $cabinet_url = SITE_URL . "/pages/cabinet.php?tab=webinars";
    $unsubscribe_url = SITE_URL . "/pages/unsubscribe.php?token=test";

    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host = SMTP_HOST;
    $mail->Port = SMTP_PORT;
    $mail->CharSet = "UTF-8";

    if (!empty(SMTP_USERNAME) && !empty(SMTP_PASSWORD)) {
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        if (SMTP_PORT == 465) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_PORT == 587) {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        }
    }

    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($testEmail);

    ob_start();
    include BASE_PATH . "/includes/email-templates/webinar_broadcast_link.php";
    $htmlBody = ob_get_clean();

    $mail->isHTML(true);
    $mail->Subject = "Cherez 1 chas nachalo! Ssylka na vebinar vnutri";
    $mail->Body = $htmlBody;
    $mail->AltBody = strip_tags($htmlBody);

    $mail->send();
    echo "Email sent to: " . $testEmail . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

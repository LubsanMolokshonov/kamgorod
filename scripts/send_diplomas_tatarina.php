<?php
/**
 * Разовый скрипт: повторная отправка документов Татариной Т.А. (user_id=3649, заказ 2250)
 * на оба адреса: shigapova71@mail.ru (заказчик) и shigapova1971@gmail.com (аккаунт Татариной).
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/send_diplomas_tatarina.php
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$userId       = 3649;
$orderNumber  = 'ORD-20260413-4B0A18';
$fullName     = 'Татарина Татьяна Александровна';
$recipients   = [
    'shigapova71@mail.ru'    => 'Шигапова Елена Феликсовна',
    'shigapova1971@gmail.com' => $fullName,
];

// Дипломы Татариной (заказ 2250)
$competitionPdf = BASE_PATH . '/uploads/diplomas/diploma_971_participant_1776095796.pdf';
$olympiadPdf    = BASE_PATH . '/uploads/diplomas/olympiad_diploma_208_participant_1776095797.pdf';

foreach ([$competitionPdf, $olympiadPdf] as $p) {
    if (!file_exists($p)) {
        fwrite(STDERR, "[FAIL] Файл не найден: {$p}\n");
        exit(1);
    }
}

$cabinetUrl = generateMagicUrl($userId, '/kabinet/?tab=events', 30);

$subject = 'Документы Татариной Т.А. по заказу ' . $orderNumber;

$htmlBody = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;background:#f0f4f8;margin:0;padding:0;">'
    . '<div style="padding:30px 15px;">'
    . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">'
    . '<div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px 40px;text-align:center;">'
    . '<h1 style="color:#fff;margin:0;font-size:22px;font-weight:600;">Ваши документы</h1>'
    . '</div>'
    . '<div style="padding:30px 40px;">'
    . '<p style="font-size:16px;margin-top:0;">Здравствуйте!</p>'
    . '<p style="font-size:15px;">Высылаем повторно документы по заказу №' . htmlspecialchars($orderNumber) . ', оформленному на имя <strong>' . htmlspecialchars($fullName) . '</strong>. Оплата прошла успешно 13.04.2026.</p>'
    . '<p style="font-size:15px;">К письму прикреплены два файла:</p>'
    . '<ul style="font-size:15px;">'
    . '<li>Диплом победителя конкурса «Воспитатель ГПД: методические находки» (1 место, номинация «Программа ГПД»)</li>'
    . '<li>Диплом победителя олимпиады «Методика работы воспитателя ГПД» (1 место, 10 баллов)</li>'
    . '</ul>'
    . '<p style="font-size:15px;">Документы также доступны в личном кабинете Татьяны Александровны:</p>'
    . '<div style="text-align:center;margin:25px 0;">'
    . '<a href="' . htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;text-decoration:none;padding:14px 35px;border-radius:8px;font-size:15px;font-weight:600;">Открыть личный кабинет</a>'
    . '</div>'
    . '<p style="font-size:14px;color:#888;">Ссылка действует 30 дней и открывает кабинет автоматически без ввода пароля.</p>'
    . '<hr style="border:none;border-top:1px solid #eee;margin:25px 0;">'
    . '<p style="font-size:14px;color:#666;">Если предыдущее письмо не пришло — скорее всего оно попало в папку «Спам» на ящике <strong>shigapova1971@gmail.com</strong> (именно на него был зарегистрирован аккаунт Татьяны Александровны). Это письмо мы дублируем на оба ваших адреса.</p>'
    . '</div>'
    . '<div style="background:#f8f9fa;padding:20px 40px;text-align:center;border-top:1px solid #e9ecef;">'
    . '<p style="font-size:13px;color:#888;margin:0;">С уважением, команда портала «Каменный город» — <a href="' . SITE_URL . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</div>'
    . '</div></div></body></html>';

$textBody = "Здравствуйте!\n\n"
    . "Высылаем повторно документы по заказу №{$orderNumber}, оформленному на имя {$fullName}. Оплата прошла успешно 13.04.2026.\n\n"
    . "К письму прикреплены два файла:\n"
    . "- Диплом победителя конкурса «Воспитатель ГПД: методические находки» (1 место, номинация «Программа ГПД»)\n"
    . "- Диплом победителя олимпиады «Методика работы воспитателя ГПД» (1 место, 10 баллов)\n\n"
    . "Документы также доступны в личном кабинете:\n{$cabinetUrl}\n(ссылка действует 30 дней)\n\n"
    . "Если предыдущее письмо не пришло — скорее всего оно попало в папку «Спам» на ящике shigapova1971@gmail.com (именно на него был зарегистрирован аккаунт Татьяны Александровны).\n\n"
    . "С уважением,\nКоманда портала «Каменный город»\n" . SITE_URL . "\n";

@ob_end_flush();
ob_implicit_flush(true);

foreach ($recipients as $email => $name) {
    echo "Отправляю -> {$name} <{$email}>...\n"; flush();
    try {
        echo "  step1: new PHPMailer\n"; flush();
        $mail = new PHPMailer(true);
        echo "  step2: created\n"; flush();
        $mail->Timeout = 20;
        $mail->SMTPDebug = 2;
        $mail->Debugoutput = function($str, $level) { echo "[smtp:{$level}] " . trim($str) . "\n"; flush(); };
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->Timeout    = 20;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = SMTP_PORT == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email, $name);
        $mail->isHTML(true);
        $mail->Encoding = 'base64';
        $mail->Subject  = $subject;
        $mail->Body     = $htmlBody;
        $mail->AltBody  = $textBody;
        $mail->addAttachment($competitionPdf, 'Диплом_конкурс_Воспитатель_ГПД.pdf');
        $mail->addAttachment($olympiadPdf,    'Диплом_олимпиада_Воспитатель_ГПД.pdf');
        $mail->send();
        echo "[OK]\n";
    } catch (Exception $e) {
        echo "[FAIL] " . $e->getMessage() . "\n";
    }
}

<?php
/**
 * Разовый скрипт: отправка обоих дипломов Набоковой Ирине (user_id=22)
 * с magic-ссылкой на личный кабинет.
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/send_diplomas_nabokova.php
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$userId = 22;
$email  = 'nabokova01021981@mail.ru';

$stmt = $db->prepare("
    SELECT r.id AS reg_id, o.title AS olympiad_title, r.placement
    FROM olympiad_registrations r
    JOIN olympiads o ON o.id = r.olympiad_id
    JOIN olympiad_diplomas d ON d.olympiad_registration_id = r.id AND d.recipient_type = 'participant'
    WHERE r.user_id = ? AND r.status = 'diploma_ready'
    ORDER BY r.id
");
$stmt->execute([$userId]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) {
    echo "Нет готовых дипломов для user_id={$userId}\n";
    exit(1);
}

$fullName   = 'Ирина Владимировна';
$cabinetUrl = generateMagicUrl($userId, '/kabinet/?tab=events', 30);
$siteUrl    = SITE_URL;

$placementLabels = ['1' => '1 место', '2' => '2 место', '3' => '3 место'];

$diplomaListHtml = '';
$diplomaListText = '';
foreach ($rows as $r) {
    $title = htmlspecialchars($r['olympiad_title'], ENT_QUOTES, 'UTF-8');
    $pl    = $placementLabels[$r['placement']] ?? ($r['placement'] . ' место');
    $diplomaListHtml .= '<div style="background:#f8f5ff;border-radius:8px;padding:16px 20px;margin:12px 0;border-left:4px solid #764ba2;">'
        . '<p style="margin:0 0 4px;font-size:14px;color:#666;">Олимпиада:</p>'
        . '<p style="margin:0 0 8px;font-size:15px;font-weight:600;color:#333;">' . $title . '</p>'
        . '<p style="margin:0;font-size:14px;color:#666;">Результат: <strong style="color:#f59e0b;">' . $pl . '</strong></p>'
        . '</div>';
    $diplomaListText .= '• ' . $r['olympiad_title'] . ' — ' . $pl . "\n";
}

$subject = 'Ваши дипломы олимпиад готовы к скачиванию';

$htmlBody = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:sans-serif;line-height:1.6;color:#333;margin:0;padding:0;background:#f0f4f8;">'
    . '<div style="background:#f0f4f8;padding:30px 15px;">'
    . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">'
    . '<div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px 40px;text-align:center;">'
    . '<h1 style="color:#fff;margin:0;font-size:22px;font-weight:600;">Ваши дипломы готовы!</h1>'
    . '</div>'
    . '<div style="padding:35px 40px;">'
    . '<p style="font-size:16px;margin-top:0;">Здравствуйте, ' . $fullName . '!</p>'
    . '<p style="font-size:15px;">Поздравляем с успешным прохождением олимпиад. Вы получили дипломы за участие в двух мероприятиях:</p>'
    . $diplomaListHtml
    . '<p style="font-size:15px;margin-top:24px;">Чтобы скачать дипломы, нажмите кнопку ниже — вы войдёте в кабинет автоматически:</p>'
    . '<div style="text-align:center;margin:30px 0;">'
    . '<a href="' . htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;text-decoration:none;padding:14px 35px;border-radius:8px;font-size:16px;font-weight:600;">Перейти в личный кабинет</a>'
    . '</div>'
    . '<p style="font-size:14px;color:#888;">Ссылка действует 30 дней.</p>'
    . '</div>'
    . '<div style="background:#f8f9fa;padding:25px 40px;text-align:center;border-top:1px solid #e9ecef;">'
    . '<p style="font-size:13px;color:#888;margin:0 0 8px;">С уважением, команда ФГОС-Практикум</p>'
    . '<p style="font-size:13px;color:#888;margin:0;"><a href="' . $siteUrl . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</div>'
    . '</div></div></body></html>';

$textBody = "Здравствуйте, {$fullName}!\n\n"
    . "Поздравляем с успешным прохождением олимпиад!\n\n"
    . $diplomaListText . "\n"
    . "Скачайте дипломы в личном кабинете:\n{$cabinetUrl}\n\n"
    . "Ссылка действует 30 дней.\n\n"
    . "С уважением,\nКоманда ФГОС-Практикум\n{$siteUrl}\n";

echo "Отправляю письмо:\n";
echo "  Кому: {$fullName} <{$email}>\n";
echo "  Дипломов: " . count($rows) . "\n";
foreach ($rows as $r) {
    echo "  - reg#{$r['reg_id']}: {$r['olympiad_title']}\n";
}
echo "\n";

try {
    $mail = new PHPMailer(true);
    $mail->isSMTP();
    $mail->Host      = SMTP_HOST;
    $mail->Port      = SMTP_PORT;
    $mail->CharSet   = 'UTF-8';
    $mail->Timeout   = 15;
    $mail->SMTPAuth  = true;
    $mail->Username  = SMTP_USERNAME;
    $mail->Password  = SMTP_PASSWORD;
    $mail->SMTPSecure = SMTP_PORT == 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email, $fullName);
    $mail->isHTML(true);
    $mail->Encoding = 'base64';
    $mail->Subject  = $subject;
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody;
    $mail->send();
    echo "[OK] Письмо отправлено!\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

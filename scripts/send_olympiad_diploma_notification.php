<?php
/**
 * Разовый скрипт: отправка уведомления пользователю о готовом дипломе олимпиады
 * с magic-ссылкой на личный кабинет (вкладка «Олимпиады»).
 *
 * Запуск:
 *   php scripts/send_olympiad_diploma_notification.php
 */

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Параметры пользователя
$userId = 3740;
$email = 'uliya_ss@list.ru';

// Получаем данные
$stmt = $db->prepare("
    SELECT u.full_name, oreg.id as reg_id, o.title as olympiad_title, oreg.placement
    FROM users u
    JOIN olympiad_registrations oreg ON oreg.user_id = u.id
    JOIN olympiads o ON o.id = oreg.olympiad_id
    WHERE u.id = ? AND oreg.status IN ('paid', 'diploma_ready')
    ORDER BY oreg.created_at DESC
    LIMIT 1
");
$stmt->execute([$userId]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    echo "Нет оплаченных олимпиадных регистраций для user_id={$userId}\n";
    exit(1);
}

$fullName = $data['full_name'];
$olympiadTitle = $data['olympiad_title'];
$placement = $data['placement'];

// Генерируем magic-link на вкладку олимпиад
$cabinetUrl = generateMagicUrl($userId, '/kabinet/?tab=events');
$unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
$unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

$placementLabels = ['1' => '1 место', '2' => '2 место', '3' => '3 место'];
$placementLabel = $placementLabels[$placement] ?? $placement . ' место';

echo "Отправка уведомления:\n";
echo "  Кому: {$fullName} <{$email}>\n";
echo "  Олимпиада: {$olympiadTitle}\n";
echo "  Место: {$placementLabel}\n";
echo "  Magic-link: {$cabinetUrl}\n\n";

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

    $emailSubject = 'Ваш диплом олимпиады готов к скачиванию';
    $email_subject = $emailSubject;
    $site_url = SITE_URL;

    // HTML-тело письма
    ob_start();
    include BASE_PATH . '/includes/email-templates/_base_layout.php';
    $baseStart = ob_get_clean();

    // Используем inline HTML с базовым стилем
    $htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$emailSubject}</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; background-color: #f0f4f8;">
<div style="background-color: #f0f4f8; padding: 30px 15px;">
<div style="max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 12px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.08);">

    <!-- Header -->
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); padding: 30px 40px; text-align: center;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px; font-weight: 600;">Ваш диплом готов!</h1>
    </div>

    <!-- Body -->
    <div style="padding: 35px 40px;">
        <p style="font-size: 16px; margin-top: 0;">Здравствуйте, {$fullName}!</p>

        <p style="font-size: 15px;">Поздравляем с успешным прохождением олимпиады! Ваш диплом оформлен и готов к скачиванию.</p>

        <div style="background-color: #f8f5ff; border-radius: 8px; padding: 20px 25px; margin: 25px 0; border-left: 4px solid #764ba2;">
            <p style="margin: 0 0 8px 0; font-size: 14px; color: #666;">Олимпиада:</p>
            <p style="margin: 0 0 12px 0; font-size: 15px; font-weight: 600; color: #333;">{$olympiadTitle}</p>
            <p style="margin: 0; font-size: 14px; color: #666;">Результат: <strong style="color: #f59e0b;">{$placementLabel}</strong></p>
        </div>

        <p style="font-size: 15px;">Чтобы скачать диплом, перейдите в личный кабинет:</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{$cabinetUrl}" style="display: inline-block; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; padding: 14px 35px; border-radius: 8px; font-size: 16px; font-weight: 600;">
                Скачать диплом
            </a>
        </div>

        <p style="font-size: 14px; color: #888;">Если кнопка не работает, скопируйте ссылку:<br>
        <a href="{$cabinetUrl}" style="color: #667eea; word-break: break-all;">{$cabinetUrl}</a></p>
    </div>

    <!-- Footer -->
    <div style="background-color: #f8f9fa; padding: 25px 40px; text-align: center; border-top: 1px solid #e9ecef;">
        <p style="font-size: 13px; color: #888; margin: 0 0 8px 0;">С уважением, команда ФГОС-Практикум</p>
        <p style="font-size: 13px; color: #888; margin: 0 0 8px 0;"><a href="{$site_url}" style="color: #667eea;">fgos.pro</a></p>
        <p style="font-size: 11px; color: #aaa; margin: 0;"><a href="{$unsubscribeUrl}" style="color: #aaa;">Отписаться от рассылки</a></p>
    </div>

</div>
</div>
</body>
</html>
HTML;

    // Plain text
    $textBody = "Здравствуйте, {$fullName}!\n\n";
    $textBody .= "Поздравляем с успешным прохождением олимпиады!\n\n";
    $textBody .= "Олимпиада: {$olympiadTitle}\n";
    $textBody .= "Результат: {$placementLabel}\n\n";
    $textBody .= "Ваш диплом оформлен и готов к скачиванию.\n";
    $textBody .= "Перейдите в личный кабинет: {$cabinetUrl}\n\n";
    $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";
    $textBody .= "{$site_url}\n\n";
    $textBody .= "Отписаться: {$unsubscribeUrl}\n";

    $mail->isHTML(true);
    $mail->Subject = mb_encode_mimeheader($emailSubject, 'UTF-8', 'B');
    $mail->Body = $htmlBody;
    $mail->AltBody = $textBody;

    // Заголовки отписки
    $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
    $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

    $mail->send();

    echo "[OK] Письмо отправлено: {$fullName} <{$email}>\n";

} catch (Exception $e) {
    echo "[FAIL] Ошибка: " . $e->getMessage() . "\n";
    exit(1);
}

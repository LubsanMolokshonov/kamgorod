<?php
/**
 * Одноразовый скрипт: ответ пользователю по support_alerts.id=1
 * (kaskinowa2017@yandex.ru — Азнабаев Кирилл, user_id=3920).
 *
 * Жалоба: «двойная оплата и получение предыдущего диплома».
 * По факту 22.04.2026 оплачены два РАЗНЫХ продукта по 169₽:
 *   - заказ 2552 → конкурс «Русский язык и литература: внеурочка (5-8 кл)», рег. 1077
 *   - заказ 2553 → олимпиада «Русский язык 5-8 кл», рег. 495
 * Оба диплома прикладываем, объясняем разницу, предлагаем возврат по запросу.
 *
 * Запуск (на проде, внутри docker pedagogy_web):
 *   docker exec pedagogy_web php /var/www/html/scripts/send_alert_1_explanation.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;

$ALERT_ID = 1;
$TO_EMAIL = 'kaskinowa2017@yandex.ru';
$TO_NAME  = 'Пользователь';

$ORDER_COMPETITION = 'ORD-20260422-70D44C';
$ORDER_OLYMPIAD    = 'ORD-20260422-4737F8';

// Проверяем, что алерт существует
$stmt = $db->prepare("SELECT id, user_email FROM support_alerts WHERE id = ?");
$stmt->execute([$ALERT_ID]);
$alert = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$alert) {
    fwrite(STDERR, "Алерт #{$ALERT_ID} не найден\n");
    exit(1);
}

// Подтягиваем PDF-вложения
$attachments = [];

$stmt = $db->prepare("SELECT pdf_path FROM diplomas WHERE registration_id = 1077 AND recipient_type = 'participant' ORDER BY generated_at DESC LIMIT 1");
$stmt->execute();
$compDiploma = $stmt->fetch(PDO::FETCH_ASSOC);
if ($compDiploma) {
    $path = BASE_PATH . '/uploads/diplomas/' . $compDiploma['pdf_path'];
    if (file_exists($path)) {
        $attachments[] = [
            'path' => $path,
            'name' => 'Диплом_конкурса_Русский_язык_внеурочка.pdf',
        ];
    } else {
        fwrite(STDERR, "PDF конкурса не найден: $path\n");
        exit(1);
    }
}

$stmt = $db->prepare("SELECT pdf_path FROM olympiad_diplomas WHERE olympiad_registration_id = 495 AND recipient_type = 'participant' ORDER BY generated_at DESC LIMIT 1");
$stmt->execute();
$olympDiploma = $stmt->fetch(PDO::FETCH_ASSOC);
if ($olympDiploma) {
    $path = BASE_PATH . '/uploads/diplomas/' . $olympDiploma['pdf_path'];
    if (file_exists($path)) {
        $attachments[] = [
            'path' => $path,
            'name' => 'Диплом_олимпиады_Русский_язык_5-8_класс.pdf',
        ];
    } else {
        fwrite(STDERR, "PDF олимпиады не найден: $path\n");
        exit(1);
    }
}

if (count($attachments) !== 2) {
    fwrite(STDERR, "Ожидалось 2 вложения, найдено " . count($attachments) . "\n");
    exit(1);
}

$subject = "[Алерт #{$ALERT_ID}] Разъяснение по заказам {$ORDER_COMPETITION} и {$ORDER_OLYMPIAD}";

$bodyText = <<<TXT
Здравствуйте!

Спасибо, что написали нам. Мы разобрались с вашей ситуацией.

22 апреля 2026 в личном кабинете Азнабаева Кирилла были оформлены и оплачены два РАЗНЫХ продукта по 169 рублей каждый. Это не повторная оплата одного и того же диплома:

1. Заказ {$ORDER_COMPETITION} (08:09) — диплом за участие в КОНКУРСЕ «Русский язык и литература: внеурочка (5-8 класс)», номинация «Викторина», работа «В мире русского языка», 1 место.

2. Заказ {$ORDER_OLYMPIAD} (08:20) — диплом за участие в ОЛИМПИАДЕ «Олимпиада по русскому языку для 5-8 классов», 1 место (9 баллов из 10).

Оба документа выписаны на одного и того же ребёнка и относятся к русскому языку — поэтому визуально они похожи. Но это два разных типа документа: один — диплом конкурса, второй — диплом олимпиады. Обратите внимание на заголовки в прикреплённых PDF: «Диплом конкурса …» и «Диплом олимпиады …».

К этому письму прикреплены оба диплома.

Если вы всё же хотите оформить возврат за один из заказов — просто ответьте на это письмо и укажите, по какому именно заказу ({$ORDER_COMPETITION} или {$ORDER_OLYMPIAD}). Мы обработаем возврат на карту, которой производилась оплата, в течение 3-5 рабочих дней.

С уважением,
служба поддержки fgos.pro
TXT;

$bodyHtml = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head><body style="font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #1F2937;">'
    . '<div style="max-width: 640px; margin: 0 auto; padding: 24px;">'
    . '<div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 24px; border-radius: 8px 8px 0 0;">'
    . '<h2 style="margin: 0;">Разъяснение по вашим заказам</h2>'
    . '</div>'
    . '<div style="background: #F9FAFB; padding: 24px; border-radius: 0 0 8px 8px;">'
    . '<p>Здравствуйте!</p>'
    . '<p>Спасибо, что написали нам. Мы разобрались с вашей ситуацией.</p>'
    . '<p>22 апреля 2026 в личном кабинете Азнабаева Кирилла были оформлены и оплачены <strong>два разных продукта</strong> по 169 ₽ каждый. Это <strong>не</strong> повторная оплата одного и того же диплома:</p>'
    . '<ol>'
    . '<li><p><strong>Заказ ' . $ORDER_COMPETITION . '</strong> (08:09) — диплом за участие в <strong>конкурсе</strong> «Русский язык и литература: внеурочка (5-8 класс)», номинация «Викторина», работа «В мире русского языка», 1 место.</p></li>'
    . '<li><p><strong>Заказ ' . $ORDER_OLYMPIAD . '</strong> (08:20) — диплом за участие в <strong>олимпиаде</strong> «Олимпиада по русскому языку для 5-8 классов», 1 место (9 баллов из 10).</p></li>'
    . '</ol>'
    . '<p>Оба документа выписаны на одного и того же ребёнка и относятся к русскому языку — поэтому визуально они похожи. Но это два разных типа документа: один — диплом конкурса, второй — диплом олимпиады. Обратите внимание на заголовки в прикреплённых PDF: «Диплом конкурса …» и «Диплом олимпиады …».</p>'
    . '<div style="background: #ECFDF5; border-left: 4px solid #10B981; padding: 12px 16px; border-radius: 6px; margin: 16px 0;">К этому письму прикреплены <strong>оба диплома</strong>.</div>'
    . '<p>Если вы всё же хотите оформить возврат за один из заказов — просто <strong>ответьте на это письмо</strong> и укажите, по какому именно заказу (' . $ORDER_COMPETITION . ' или ' . $ORDER_OLYMPIAD . '). Мы обработаем возврат на карту, которой производилась оплата, в течение 3-5 рабочих дней.</p>'
    . '<p style="margin-top: 24px; color: #6B7280; font-size: 14px;">С уважением,<br>служба поддержки fgos.pro</p>'
    . '</div></div></body></html>';

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
$mail->addAddress($TO_EMAIL, $TO_NAME);
$mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
$mail->isHTML(true);
$mail->Subject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
$mail->Body = $bodyHtml;
$mail->AltBody = $bodyText;

foreach ($attachments as $att) {
    $mail->addAttachment($att['path'], $att['name']);
}

// Уникальный Message-ID — пригодится для маппинга входящих ответов через In-Reply-To
$messageId = sprintf('<alert-%d-%d@%s>', $ALERT_ID, time(), parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro');
$mail->MessageID = $messageId;

if (!$mail->send()) {
    fwrite(STDERR, "Ошибка отправки: " . $mail->ErrorInfo . "\n");
    exit(1);
}

// Логируем исходящее письмо в alert_messages
$attachmentsJson = json_encode(array_map(fn($a) => ['name' => $a['name']], $attachments), JSON_UNESCAPED_UNICODE);
$stmt = $db->prepare(
    "INSERT INTO alert_messages
        (alert_id, direction, from_email, from_name, to_email, subject, body_html, body_text, attachments_json, message_id)
     VALUES (?, 'outbound', ?, ?, ?, ?, ?, ?, ?, ?)"
);
$stmt->execute([
    $ALERT_ID,
    SMTP_FROM_EMAIL,
    SMTP_FROM_NAME,
    $TO_EMAIL,
    $subject,
    $bodyHtml,
    $bodyText,
    $attachmentsJson,
    $messageId,
]);

echo "OK: письмо отправлено на {$TO_EMAIL}, alert_messages.id=" . $db->lastInsertId() . "\n";

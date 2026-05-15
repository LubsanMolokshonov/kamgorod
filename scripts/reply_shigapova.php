<?php
/**
 * Разовый: ответ Шигаповой Е.Ф. по её вопросу о документах Татариной Т.А.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/config.php';

use PHPMailer\PHPMailer\PHPMailer;

$to     = 'shigapova71@mail.ru';
$toName = 'Шигапова Елена Феликсовна';

$subject = 'Re: документы по заказу для Татариной Т.А.';

$html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;font-size:15px;">'
    . '<p>Здравствуйте, Елена Феликсовна!</p>'
    . '<p>Проверили вашу ситуацию по обращению. Спешим успокоить — с оплатой и документами всё в порядке.</p>'
    . '<p><strong>По оплате:</strong> деньги списались корректно один раз. Заказ №ORD-20260413-4B0A18 на сумму 318 ₽ за Татарину Татьяну Александровну успешно оплачен 13.04.2026 в 11:56 (платёж подтверждён банком и ЮKassa). Дублирующих списаний не было.</p>'
    . '<p><strong>Почему документы Татьяны Александровны не пришли:</strong> при оформлении вы зарегистрировали для неё отдельный аккаунт на адрес <strong>shigapova1971@gmail.com</strong>. Именно туда автоматически ушло письмо с её дипломами — скорее всего, оно попало в папку «Спам» (Gmail часто так делает с письмами от незнакомых отправителей).</p>'
    . '<p><strong>Что мы сделали:</strong> только что повторно выслали оба диплома Татьяны Александровны на ваши адреса — и на shigapova71@mail.ru, и на shigapova1971@gmail.com. Проверьте, пожалуйста, входящие и «Спам». Письмо с темой «Документы Татариной Т.А. по заказу ORD-20260413-4B0A18», во вложении два PDF:</p>'
    . '<ul>'
    . '<li>Диплом конкурса «Воспитатель ГПД: методические находки» (1 место)</li>'
    . '<li>Диплом олимпиады «Методика работы воспитателя ГПД» (1 место)</li>'
    . '</ul>'
    . '<p>Если письмо не найдёте — напишите в ответ, вышлю файлы ещё раз или другим способом.</p>'
    . '<p>С уважением,<br>служба поддержки портала «Каменный город»<br><a href="' . SITE_URL . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</body></html>';

$text = "Здравствуйте, Елена Феликсовна!\n\n"
    . "Проверили вашу ситуацию. С оплатой и документами всё в порядке.\n\n"
    . "По оплате: деньги списались корректно один раз. Заказ №ORD-20260413-4B0A18 на сумму 318 руб. за Татарину Татьяну Александровну успешно оплачен 13.04.2026 в 11:56 (подтверждён банком и ЮKassa). Дублирующих списаний нет.\n\n"
    . "Почему документы Татьяны Александровны не пришли: при оформлении вы зарегистрировали для неё отдельный аккаунт на адрес shigapova1971@gmail.com. Именно туда ушло письмо с её дипломами — скорее всего, оно попало в папку «Спам» (Gmail часто так делает).\n\n"
    . "Что мы сделали: только что повторно выслали оба диплома Татьяны Александровны на ваши адреса — и на shigapova71@mail.ru, и на shigapova1971@gmail.com. Проверьте, пожалуйста, входящие и «Спам». Письмо с темой «Документы Татариной Т.А. по заказу ORD-20260413-4B0A18», во вложении два PDF:\n"
    . "  - Диплом конкурса «Воспитатель ГПД: методические находки» (1 место)\n"
    . "  - Диплом олимпиады «Методика работы воспитателя ГПД» (1 место)\n\n"
    . "Если письмо не найдёте — напишите в ответ.\n\n"
    . "С уважением,\nслужба поддержки портала «Каменный город»\n" . SITE_URL . "\n";

try {
    $mail = new PHPMailer(true);
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
    $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($to, $toName);
    $mail->isHTML(true);
    $mail->Encoding = 'base64';
    $mail->Subject  = $subject;
    $mail->Body     = $html;
    $mail->AltBody  = $text;
    $mail->send();
    echo "[OK] reply sent to {$to}\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

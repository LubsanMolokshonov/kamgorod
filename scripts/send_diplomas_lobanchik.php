<?php
/**
 * Разовый скрипт: пользователь lobanchik_30@mail.ru (user_id=5183) оплатила
 * 30.04.2026 две позиции конкурса «Ансамблевое пение» (заказы 2782 и 2784),
 * но обе оплаты ушли на одну регистрацию #1156, поэтому диплом сгенерирован
 * один — на имя «Волков Олег». По её просьбе нужно второй диплом — на
 * «Матвеева Юлия» (тот же конкурс/работа/руководитель).
 *
 * Скрипт:
 *  1) Генерирует второй PDF c именем «Матвеева Юлия» через Diploma::generatePDF
 *     (вызов приватного метода через Reflection — данные в БД не меняем).
 *  2) Сохраняет файл в /uploads/diplomas/.
 *  3) В режиме SEND=true отправляет письмо с обоими PDF во вложении через
 *     транзакционный SMTP (info@fgos.pro).
 *
 * Запуск:
 *   docker exec pedagogy_web php /var/www/html/scripts/send_diplomas_lobanchik.php          # dry-run, только генерация
 *   docker exec pedagogy_web php /var/www/html/scripts/send_diplomas_lobanchik.php --send   # сгенерировать и отправить
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Diploma.php';
require_once BASE_PATH . '/includes/email-helper.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$SEND = in_array('--send', $argv ?? [], true);

$userId          = 5183;
$registrationId  = 1156;
$email           = 'lobanchik_30@mail.ru';
$customerName    = 'Шатыло Алиса';

$existingPdf     = BASE_PATH . '/uploads/diplomas/diploma_1156_participant_1777550250.pdf'; // Волков Олег
$secondName      = 'Юлия Матвеева';

if (!file_exists($existingPdf)) {
    fwrite(STDERR, "[FAIL] Существующий диплом не найден: {$existingPdf}\n");
    exit(1);
}

// === 1. Генерируем второй PDF с подменённым ФИО ===
$diploma = new Diploma($db);

$registration = $diploma->getRegistrationData($registrationId);
if (!$registration) {
    fwrite(STDERR, "[FAIL] Регистрация {$registrationId} не найдена\n");
    exit(1);
}

// Подменяем имя получателя (только в массиве, в БД не трогаем)
$registration['user_full_name'] = $secondName;

$reflection = new ReflectionClass(Diploma::class);

$getTemplate = $reflection->getMethod('getTemplate');
$getTemplate->setAccessible(true);
$template = $getTemplate->invoke($diploma, $registration['diploma_template_id']);
if (!$template) {
    fwrite(STDERR, "[FAIL] Шаблон {$registration['diploma_template_id']} не найден\n");
    exit(1);
}

$generatePDF = $reflection->getMethod('generatePDF');
$generatePDF->setAccessible(true);
$newFilename = $generatePDF->invoke($diploma, $registration, $template, 'participant');

$uploadsProp = $reflection->getProperty('uploadsDir');
$uploadsProp->setAccessible(true);
$uploadsDir = $uploadsProp->getValue($diploma);

$newPdfPath = $uploadsDir . $newFilename;
if (!file_exists($newPdfPath)) {
    fwrite(STDERR, "[FAIL] Сгенерированный файл не найден: {$newPdfPath}\n");
    exit(1);
}

echo "[OK] Сгенерирован новый диплом: {$newPdfPath}\n";
echo "     Имя на дипломе: {$secondName}\n";
echo "     Размер: " . filesize($newPdfPath) . " байт\n";
echo "[OK] Существующий диплом (Волков Олег): {$existingPdf}\n";
echo "     Размер: " . filesize($existingPdf) . " байт\n";

if (!$SEND) {
    echo "\n[DRY-RUN] Письмо не отправлено. Для отправки запусти с флагом --send\n";
    exit(0);
}

// === 2. Отправляем письмо с обоими PDF во вложении ===
$cabinetUrl = generateMagicUrl($userId, '/kabinet/?tab=events', 30);
$siteUrl    = SITE_URL;

$subject = 'Ваши дипломы конкурса «Ансамблевое пение» — Олег Волков и Юлия Матвеева';

$htmlBody = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;background:#f0f4f8;margin:0;padding:0;">'
    . '<div style="padding:30px 15px;">'
    . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,0.08);">'
    . '<div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);padding:30px 40px;text-align:center;">'
    . '<h1 style="color:#fff;margin:0;font-size:22px;font-weight:600;">Ваши дипломы готовы</h1>'
    . '</div>'
    . '<div style="padding:35px 40px;">'
    . '<p style="font-size:16px;margin-top:0;">Здравствуйте!</p>'
    . '<p style="font-size:15px;">Спасибо, что написали — мы разобрались с ситуацией. Действительно, при оформлении на сайте была создана одна регистрация на конкурс <strong>«Ансамблевое пение»</strong> (работа «И всё о той весне»), а оплата прошла дважды. Поэтому в личном кабинете у Вас отображался только один диплом — на имя <strong>Олега Волкова</strong>.</p>'
    . '<p style="font-size:15px;">Мы вручную выпустили <strong>второй диплом — на имя Юлии Матвеевой</strong> (тот же конкурс, та же работа, тот же руководитель Лукина О.В., 1 место). Возврат не требуется — оба диплома Вы найдёте во вложении к этому письму.</p>'
    . '<div style="background:#f8f5ff;border-left:4px solid #764ba2;border-radius:8px;padding:16px 20px;margin:20px 0;">'
    . '<p style="margin:0 0 8px;font-size:14px;color:#666;">Во вложении:</p>'
    . '<p style="margin:0;font-size:15px;">Диплом — Олег Волков<br>Диплом — Юлия Матвеева</p>'
    . '</div>'
    . '<p style="font-size:15px;">Если в ФИО участников нужны отчества или есть опечатка — просто ответьте на это письмо, мы перевыпустим.</p>'
    . '<p style="font-size:15px;margin-top:24px;">Также Вы можете войти в личный кабинет по этой ссылке (действует 30 дней):</p>'
    . '<div style="text-align:center;margin:25px 0;">'
    . '<a href="' . htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;text-decoration:none;padding:12px 30px;border-radius:8px;font-size:15px;font-weight:600;">Перейти в личный кабинет</a>'
    . '</div>'
    . '<p style="font-size:14px;color:#888;margin-top:24px;">На будущее: чтобы получить дипломы на нескольких участников одного ансамбля, нужно оформить отдельную регистрацию на каждого ребёнка — введите тот же email, корзина объединит позиции и можно оплатить одной кнопкой со скидкой по акции «2+1».</p>'
    . '<p style="font-size:15px;margin-top:24px;">Извините за неудобства!</p>'
    . '</div>'
    . '<div style="background:#f8f9fa;padding:25px 40px;text-align:center;border-top:1px solid #e9ecef;">'
    . '<p style="font-size:13px;color:#888;margin:0 0 8px;">С уважением, команда педагогического портала «Каменный город»</p>'
    . '<p style="font-size:13px;color:#888;margin:0;"><a href="' . $siteUrl . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</div>'
    . '</div></div></body></html>';

$textBody = "Здравствуйте!\n\n"
    . "Спасибо, что написали — мы разобрались с ситуацией.\n\n"
    . "При оформлении заказа на сайте была создана одна регистрация на конкурс «Ансамблевое пение» (работа «И всё о той весне»), а оплата прошла дважды. Поэтому в личном кабинете у Вас отображался только один диплом — на имя Олега Волкова.\n\n"
    . "Мы вручную выпустили второй диплом — на имя Юлии Матвеевой (тот же конкурс, та же работа, тот же руководитель Лукина О.В., 1 место). Возврат не требуется — оба диплома во вложении к письму.\n\n"
    . "Если в ФИО участников нужны отчества или есть опечатка — ответьте на это письмо, перевыпустим.\n\n"
    . "Личный кабинет (ссылка действует 30 дней):\n{$cabinetUrl}\n\n"
    . "На будущее: чтобы получить дипломы на нескольких участников одного ансамбля, нужно оформить отдельную регистрацию на каждого ребёнка — введите тот же email, корзина объединит позиции и можно оплатить одной кнопкой со скидкой по акции «2+1».\n\n"
    . "Извините за неудобства!\n\n"
    . "С уважением,\nКоманда педагогического портала «Каменный город»\n{$siteUrl}\n";

echo "\nОтправляю письмо:\n";
echo "  Кому: {$email}\n";
echo "  Тема: {$subject}\n";
echo "  Вложений: 2\n";
echo "    1) " . basename($existingPdf) . " (" . filesize($existingPdf) . " б)\n";
echo "    2) " . basename($newPdfPath) . " (" . filesize($newPdfPath) . " б)\n\n";

try {
    $mail = new PHPMailer(true);
    configureMailer($mail);
    $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->addAddress($email, $customerName);
    $mail->addReplyTo(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
    $mail->isHTML(true);
    $mail->Encoding = 'base64';
    $mail->Subject  = $subject;
    $mail->Body     = $htmlBody;
    $mail->AltBody  = $textBody;
    $mail->addAttachment($existingPdf, 'Диплом_Олег_Волков.pdf');
    $mail->addAttachment($newPdfPath, 'Диплом_Юлия_Матвеева.pdf');
    $mail->send();
    echo "[OK] Письмо отправлено!\n";
} catch (Exception $e) {
    echo "[FAIL] " . $e->getMessage() . "\n";
    exit(1);
}

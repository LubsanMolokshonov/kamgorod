<?php
/**
 * Разовый: исправление ФИО Ларичевой О.Е. (user 5178) и регенерация
 * её дипломов олимпиад. Конкурс 1155 не трогаем — там участник = ребёнок
 * Москаленко Артём (конкурс для детей с ОВЗ), руководитель = Ларичева О.Е.,
 * это корректно. Олимпиады 718, 719 — для воспитателей, участником там
 * должна быть сама педагог Ларичева О.Е.
 */

error_reporting(E_ALL);
ini_set('display_errors', '1');
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/OlympiadDiploma.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;

$userId       = 5178;
$correctName  = 'Ларичева Ольга Ефимовна';
$wrongName    = 'Москаленко Артём';
$email        = 'olia.laricheva@yandex.ru';
$olympiadRegs = [718, 719];

echo "=== Fix user {$userId}: {$wrongName} -> {$correctName} ===\n";

// 1. Обновляем ФИО
$stmt = $db->prepare("UPDATE users SET full_name = ? WHERE id = ? AND full_name = ?");
$stmt->execute([$correctName, $userId, $wrongName]);
echo "[users] updated rows: " . $stmt->rowCount() . "\n";

// 2. Удаляем старые PDF-файлы и записи дипломов олимпиад
$uploadsDir = BASE_PATH . '/uploads/diplomas/';

foreach ($olympiadRegs as $regId) {
    $stmt = $db->prepare("SELECT id, pdf_path FROM olympiad_diplomas WHERE olympiad_registration_id = ?");
    $stmt->execute([$regId]);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $path = $uploadsDir . $row['pdf_path'];
        if (is_file($path)) {
            unlink($path);
            echo "[file] deleted: {$row['pdf_path']}\n";
        }
    }
    $del = $db->prepare("DELETE FROM olympiad_diplomas WHERE olympiad_registration_id = ?");
    $del->execute([$regId]);
    echo "[olympiad_diplomas] deleted rows for reg {$regId}: " . $del->rowCount() . "\n";
}

// 3. Регенерируем дипломы участника
$olympiadDiploma = new OlympiadDiploma($db);
foreach ($olympiadRegs as $regId) {
    $res = $olympiadDiploma->generate($regId, 'participant');
    echo "[regen] reg {$regId}: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    if (empty($res['success'])) {
        echo "[FAIL] прерываем — диплом {$regId} не сгенерировался\n";
        exit(1);
    }
}

// 4. Magic-link на личный кабинет
$magicUrl = generateMagicUrl($userId, '/pages/cabinet.php', 14);
echo "[magic] {$magicUrl}\n";

// 5. Письмо
$subject = 'Документы исправлены — ФИО обновлено';

$html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
    . '<body style="font-family:Arial,sans-serif;line-height:1.6;color:#333;font-size:15px;">'
    . '<p>Здравствуйте, Ольга Ефимовна!</p>'
    . '<p>Получили ваше обращение — всё поправили.</p>'
    . '<p><strong>Что сделали:</strong></p>'
    . '<ul>'
    . '<li>Обновили ФИО в вашем личном кабинете на «Ларичева Ольга Ефимовна».</li>'
    . '<li>Перегенерировали дипломы по двум олимпиадам, в которых участвовали лично вы:'
    . '<ul>'
    . '<li>«Реализация ФГОС ДО в практике воспитателя» (2 место)</li>'
    . '<li>«Основы инклюзивного образования» (1 место)</li>'
    . '</ul>'
    . 'Теперь на этих дипломах указано ваше ФИО.</li>'
    . '</ul>'
    . '<p><strong>По конкурсу «Мы вместе: конкурс для детей с ОВЗ»</strong> — оставили как есть: там участником является ребёнок Москаленко Артём, а вы указаны как руководитель. Если требуется иначе — напишите, поправим.</p>'
    . '<p>Скачать обновлённые дипломы можно в личном кабинете по этой ссылке (она автоматически вас авторизует, действительна 14 дней):</p>'
    . '<p><a href="' . htmlspecialchars($magicUrl, ENT_QUOTES, 'UTF-8') . '" style="display:inline-block;padding:12px 24px;background:#667eea;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;">Войти в личный кабинет</a></p>'
    . '<p style="color:#666;font-size:13px;">Если кнопка не работает, скопируйте ссылку в адресную строку браузера:<br>'
    . '<span style="word-break:break-all;">' . htmlspecialchars($magicUrl, ENT_QUOTES, 'UTF-8') . '</span></p>'
    . '<p>С уважением,<br>служба поддержки портала «Каменный город»<br>'
    . '<a href="' . SITE_URL . '" style="color:#667eea;">fgos.pro</a></p>'
    . '</body></html>';

$text = "Здравствуйте, Ольга Ефимовна!\n\n"
    . "Получили ваше обращение — всё поправили.\n\n"
    . "Что сделали:\n"
    . "  - Обновили ФИО в вашем личном кабинете на «Ларичева Ольга Ефимовна».\n"
    . "  - Перегенерировали дипломы по двум олимпиадам, в которых вы участвовали лично:\n"
    . "      • «Реализация ФГОС ДО в практике воспитателя» (2 место)\n"
    . "      • «Основы инклюзивного образования» (1 место)\n"
    . "    Теперь на этих дипломах указано ваше ФИО.\n\n"
    . "По конкурсу «Мы вместе: конкурс для детей с ОВЗ» оставили как есть: участником там значится ребёнок Москаленко Артём, а вы — руководитель. Если требуется иначе — напишите, поправим.\n\n"
    . "Скачать обновлённые дипломы можно в личном кабинете по этой ссылке (автоматически авторизует, действительна 14 дней):\n"
    . $magicUrl . "\n\n"
    . "С уважением,\n"
    . "служба поддержки портала «Каменный город»\n"
    . SITE_URL . "\n";

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
    $mail->addAddress($email, $correctName);
    $mail->isHTML(true);
    $mail->Encoding = 'base64';
    $mail->Subject  = $subject;
    $mail->Body     = $html;
    $mail->AltBody  = $text;
    $mail->send();
    echo "[mail] OK -> {$email}\n";
} catch (Exception $e) {
    echo "[mail] FAIL: " . $e->getMessage() . "\n";
    exit(1);
}

echo "=== DONE ===\n";

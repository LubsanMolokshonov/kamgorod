<?php
/**
 * Массовая рассылка: запись вебинара "Разговоры о важном" + сертификат + следующий вебинар
 * Рассылается ВСЕМ пользователям из таблицы users.
 * Пользователи без регистрации на вебинар автоматически регистрируются.
 *
 * Запуск:
 *   php scripts/send_recording_broadcast.php --test email@test.com   # тест одному
 *   php scripts/send_recording_broadcast.php --dry-run               # проверка без отправки
 *   php scripts/send_recording_broadcast.php                         # рассылка всем
 */

// Только CLI
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

set_time_limit(0);

// === Параметры CLI ===
$dryRun = in_array('--dry-run', $argv ?? []);
$testEmail = null;
$testIdx = array_search('--test', $argv ?? []);
if ($testIdx !== false && isset($argv[$testIdx + 1])) {
    $testEmail = $argv[$testIdx + 1];
}

// === Константы ===
$webinarId = 5;
$recordingUrl = 'https://clck.ru/3RmQ2D';

// === Получаем данные вебинара ===
$webinarStmt = $db->prepare("SELECT * FROM webinars WHERE id = ?");
$webinarStmt->execute([$webinarId]);
$webinar = $webinarStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) {
    die("Вебинар ID {$webinarId} не найден\n");
}

echo "=== Массовая рассылка: запись вебинара ===\n";
echo "Вебинар: {$webinar['title']} (ID: {$webinarId})\n";
echo "Режим: " . ($testEmail ? "ТЕСТ ({$testEmail})" : ($dryRun ? "DRY RUN" : "РАССЫЛКА")) . "\n";
echo "---\n\n";

// === Загружаем отписки (O(1) lookup) ===
$unsubStmt = $db->prepare("SELECT email FROM email_unsubscribes");
$unsubStmt->execute();
$unsubscribed = array_flip(
    array_map('strtolower', array_column($unsubStmt->fetchAll(PDO::FETCH_ASSOC), 'email'))
);
echo "Отписавшихся: " . count($unsubscribed) . "\n";

// === Загружаем пользователей ===
if ($testEmail) {
    $userStmt = $db->prepare("SELECT id, email, full_name FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$testEmail]);
} else {
    $userStmt = $db->prepare("SELECT id, email, full_name FROM users ORDER BY id");
    $userStmt->execute();
}
$users = $userStmt->fetchAll(PDO::FETCH_ASSOC);
$totalUsers = count($users);

if ($totalUsers === 0) {
    die("Пользователи не найдены\n");
}

echo "Пользователей: {$totalUsers}\n\n";

// ============================================
// ФАЗА 1: Авто-регистрация
// ============================================
echo "=== Фаза 1: Авто-регистрация ===\n";

// Загружаем существующие регистрации по user_id
$existingRegStmt = $db->prepare(
    "SELECT user_id, id as registration_id FROM webinar_registrations WHERE webinar_id = ? AND user_id IS NOT NULL"
);
$existingRegStmt->execute([$webinarId]);
$existingRegs = [];
while ($row = $existingRegStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingRegs[$row['user_id']] = $row['registration_id'];
}

// Загружаем существующие регистрации по email
$existingRegByEmailStmt = $db->prepare(
    "SELECT email, id as registration_id, user_id FROM webinar_registrations WHERE webinar_id = ?"
);
$existingRegByEmailStmt->execute([$webinarId]);
$existingRegsByEmail = [];
while ($row = $existingRegByEmailStmt->fetch(PDO::FETCH_ASSOC)) {
    $existingRegsByEmail[strtolower($row['email'])] = $row;
}

// Подготовка INSERT для авто-регистрации
$insertRegStmt = $db->prepare(
    "INSERT INTO webinar_registrations (webinar_id, user_id, email, full_name, registration_source)
     VALUES (?, ?, ?, ?, 'broadcast')"
);

$autoRegistered = 0;
$alreadyRegistered = 0;
$userRegMap = []; // user_id => registration_id

foreach ($users as $user) {
    $userId = $user['id'];
    $emailLower = strtolower($user['email']);

    // Проверяем по user_id
    if (isset($existingRegs[$userId])) {
        $userRegMap[$userId] = $existingRegs[$userId];
        $alreadyRegistered++;
        continue;
    }

    // Проверяем по email
    if (isset($existingRegsByEmail[$emailLower])) {
        $regData = $existingRegsByEmail[$emailLower];
        $userRegMap[$userId] = $regData['registration_id'];

        // Привязываем user_id если не привязан
        if (empty($regData['user_id'])) {
            $updateStmt = $db->prepare("UPDATE webinar_registrations SET user_id = ? WHERE id = ?");
            $updateStmt->execute([$userId, $regData['registration_id']]);
        }
        $alreadyRegistered++;
        continue;
    }

    // Создаём новую регистрацию (без Bitrix24 и без обновления счётчика)
    if (!$dryRun) {
        $insertRegStmt->execute([$webinarId, $userId, $user['email'], $user['full_name']]);
        $regId = $db->lastInsertId();
    } else {
        $regId = 'DRY-' . $userId;
    }
    $userRegMap[$userId] = $regId;
    $autoRegistered++;
}

echo "Уже зарегистрированы: {$alreadyRegistered}\n";
echo "Авто-зарегистрированы: {$autoRegistered}\n\n";

// ============================================
// ФАЗА 2: Отправка писем
// ============================================
echo "=== Фаза 2: Отправка писем ===\n\n";

$sent = 0;
$skipped = 0;
$failed = 0;

foreach ($users as $index => $user) {
    $userId = $user['id'];
    $email = $user['email'];
    $fullName = $user['full_name'];

    // Пропускаем пользователей без ID
    if (!$userId) {
        echo "[SKIP] Нет user_id для <{$email}>\n";
        $skipped++;
        continue;
    }

    // Проверяем отписку (кроме тестового режима)
    if (!$testEmail && isset($unsubscribed[strtolower($email)])) {
        echo "[SKIP] {$fullName} <{$email}> — отписан\n";
        $skipped++;
        continue;
    }

    // Получаем registration_id
    $regId = $userRegMap[$userId] ?? null;
    if (!$regId) {
        echo "[SKIP] {$fullName} <{$email}> — нет регистрации\n";
        $skipped++;
        continue;
    }

    // Прогресс каждые 50 писем
    if (($index + 1) % 50 === 0 || $index === 0) {
        $pct = round(($index + 1) / $totalUsers * 100, 1);
        echo "--- Прогресс: " . ($index + 1) . " / {$totalUsers} ({$pct}%) ---\n";
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

        // Генерация magic-link
        $certificate_url = generateMagicUrl($userId, '/pages/webinar-certificate.php?registration_id=' . $regId);
        $cabinet_url = generateMagicUrl($userId, '/pages/cabinet.php?tab=webinars');

        // Unsubscribe
        $unsubscribeToken = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
        $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $unsubscribeToken;

        // Переменные шаблона
        $user_name = $fullName;
        $webinar_title = $webinar['title'];
        $certificate_price = $webinar['certificate_price'] ?? 200;
        $certificate_hours = $webinar['certificate_hours'] ?? 2;
        $recording_url = $recordingUrl;
        $site_url = SITE_URL;

        // Рендер HTML-шаблона
        ob_start();
        include BASE_PATH . '/includes/email-templates/webinar_recording_broadcast.php';
        $htmlBody = ob_get_clean();

        // Plain text версия
        $textBody = "Здравствуйте, {$fullName}!\n\n";
        $textBody .= "12 февраля состоялся вебинар «{$webinar_title}».\n";
        $textBody .= "Запись уже доступна — смотрите в любое удобное время!\n\n";
        $textBody .= "Смотреть запись: {$recordingUrl}\n\n";
        $textBody .= "Получите сертификат участника ({$certificate_hours} ак. часа, {$certificate_price} руб.):\n";
        $textBody .= $certificate_url . "\n\n";
        $textBody .= "Следующий вебинар: «Взаимодействие с семьями воспитанников через читательские марафоны»\n";
        $textBody .= "https://fgos.pro/vebinar/vzaimodeystvie-s-semyami-vospitannikov-cherez-chitatelskie-marafony\n\n";
        $textBody .= "С уважением,\nКоманда ФГОС-Практикум\n";
        $textBody .= SITE_URL . "\n\n";
        $textBody .= "Отписаться: {$unsubscribe_url}\n";

        $mail->isHTML(true);
        $mail->Subject = $email_subject; // из шаблона
        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Заголовки отписки
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribe_url . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        $mail->send();

        echo "[SENT] {$fullName} <{$email}> (reg #{$regId})\n";
        $sent++;

        // Пауза 200мс между письмами
        usleep(200000);

    } catch (Exception $e) {
        echo "[FAIL] {$fullName} <{$email}> — " . $e->getMessage() . "\n";
        $failed++;
    }
}

// ============================================
// РЕЗУЛЬТАТ
// ============================================
echo "\n=== Результат ===\n";
echo "Всего пользователей: {$totalUsers}\n";
echo "Отправлено: {$sent}\n";
echo "Пропущено: {$skipped}\n";
echo "Ошибки: {$failed}\n";
echo "Авто-зарегистрировано: {$autoRegistered}\n";

// Логирование
$mode = $testEmail ? "TEST({$testEmail})" : ($dryRun ? "DRY_RUN" : "SEND");
$logMessage = "[" . date('Y-m-d H:i:s') . "] RECORDING_BROADCAST | Mode: {$mode} | Total: {$totalUsers}, Sent: {$sent}, Skipped: {$skipped}, Failed: {$failed}, AutoReg: {$autoRegistered}\n";
$logFile = BASE_PATH . '/logs/webinar-email-journey.log';
error_log($logMessage, 3, $logFile);

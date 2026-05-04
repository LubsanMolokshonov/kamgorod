<?php
/**
 * Email Helper Functions
 * Handles email notifications for payments using PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/magic-link-helper.php';
require_once __DIR__ . '/../classes/TelegramNotifier.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Включена ли пауза на chain-рассылки.
 * Используется в cron/process-*-emails.php и в send-методах chain-классов:
 * пока новые ящики rodion@/kazakova@ прогреваются, лучше не отправлять
 * массовые цепочки — они тянут вниз репутацию и блокируют транзакционку.
 *
 * При указании $touchpointCode — возвращает false для whitelist'а
 * приветственных/подтверждающих писем после регистрации (без них
 * пользователь думает, что не зарегистрировался).
 */
function chainEmailsPaused(?string $touchpointCode = null): bool {
    if (!defined('CHAINS_PAUSED_UNTIL') || CHAINS_PAUSED_UNTIL === '') return false;
    $until = strtotime(CHAINS_PAUSED_UNTIL);
    if ($until === false || time() >= $until) return false;

    static $alwaysAllowed = ['webinar_confirmation', 'aw_welcome'];
    if ($touchpointCode !== null && in_array($touchpointCode, $alwaysAllowed, true)) {
        return false;
    }
    return true;
}

/**
 * Был ли получателю отправлен какой-либо учётный email за последние N минут.
 * Используется для дросселирования chain-кронов: если человек уже получал
 * письмо недавно — пропускаем (оставляем в pending), чтобы не выглядеть
 * спам-ботом перед фильтрами Яндекса/Mail.ru.
 */
function recipientRecentlyEmailed(PDO $pdo, string $email, int $minutes): bool {
    if ($minutes <= 0 || $email === '') return false;
    $stmt = $pdo->prepare(
        "SELECT 1 FROM email_events
         WHERE recipient_email = ? AND sent_at >= NOW() - INTERVAL ? MINUTE
         LIMIT 1"
    );
    $stmt->execute([$email, $minutes]);
    return (bool)$stmt->fetchColumn();
}

/**
 * Configure PHPMailer with SMTP settings
 * Supports both authenticated and relay modes
 */
function configureMailer($mail) {
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

    attachSmtpDebugLogger($mail);
    return $mail;
}

/**
 * Включить детальное логирование SMTP-диалога в logs/smtp-debug.log.
 * Нужно для разбора ответов Яндекс 360 (550/451/554), которые PHPMailer
 * иначе сворачивает в общую строку «SMTP Error: data not accepted».
 */
function attachSmtpDebugLogger($mail) {
    $mail->SMTPDebug   = 2; // 2 = client+server messages
    $mail->Debugoutput = function ($str, $level) {
        $logFile = BASE_PATH . '/logs/smtp-debug.log';
        $logDir  = dirname($logFile);
        if (!file_exists($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        $line = '[' . date('Y-m-d H:i:s') . '] [' . $level . '] ' . rtrim($str) . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND);
    };
}

/**
 * Configure PHPMailer для массовых рассылок с ротацией по двум ящикам.
 * Выбор аккаунта детерминирован по адресу получателя — у одного получателя
 * всегда один и тот же отправитель (важно для репутации в почтовом клиенте),
 * нагрузка делится 50/50 между двумя ящиками Яндекс 360.
 *
 * Если SMTP_BULK_USERNAME_1 пуст (до завершения миграции) — fallback на configureMailer().
 * В этом случае setFrom не устанавливается — вызывающий код задаёт его сам.
 */
function configureBulkMailer($mail, string $recipientEmail) {
    if (empty(SMTP_BULK_USERNAME_1) || empty(SMTP_BULK_USERNAME_2)) {
        return configureMailer($mail);
    }

    $mail->isSMTP();
    $mail->Host = SMTP_BULK_HOST;
    $mail->Port = SMTP_BULK_PORT;
    $mail->CharSet = 'UTF-8';
    $mail->SMTPAuth = true;

    $useFirst = (crc32(strtolower(trim($recipientEmail))) % 2 === 0);
    $username = $useFirst ? SMTP_BULK_USERNAME_1 : SMTP_BULK_USERNAME_2;
    $password = $useFirst ? SMTP_BULK_PASSWORD_1 : SMTP_BULK_PASSWORD_2;

    $mail->Username = $username;
    $mail->Password = $password;
    $mail->SMTPSecure = (SMTP_BULK_PORT == 465)
        ? PHPMailer::ENCRYPTION_SMTPS
        : PHPMailer::ENCRYPTION_STARTTLS;

    // Яндекс отбивает письма, у которых From не совпадает с аутентифицированным ящиком
    $mail->setFrom($username, SMTP_FROM_NAME);
    attachSmtpDebugLogger($mail);
    return $mail;
}

/**
 * Отправка письма с автоматическим ретраем транзакционных сбоев.
 *
 * Стратегия: до $maxAttempts попыток (по умолчанию 2 — итого 3 запуска).
 * После каждой неудачной попытки — пауза с экспоненциальным ростом (5с, 15с).
 * Перевыполняет SMTP-handshake целиком (новое соединение), потому что Яндекс
 * после отказа на DATA закрывает сессию.
 *
 * Имеет смысл вызывать только для одиночных транзакционных писем (платежи,
 * дипломы, magic-link). Для bulk-цепочек ретраи делает их собственный
 * шедулер по таблице *_log.
 *
 * Бросает Exception от последней попытки, если все провалились.
 */
function sendWithRetry($mail, array $trackerMeta, int $maxAttempts = 2): bool {
    require_once __DIR__ . '/../classes/EmailTracker.php';
    $attempt = 0;
    $delays  = [5, 15];
    $lastErr = null;
    while ($attempt <= $maxAttempts) {
        try {
            return EmailTracker::prepareAndSend($mail, $trackerMeta);
        } catch (Exception $e) {
            $lastErr = $e;
            $info = isset($mail->ErrorInfo) ? $mail->ErrorInfo : '';
            $msg  = $e->getMessage();
            // Не ретраим явно постоянные отказы (5xx auth/from/policy),
            // на которые повтор не поможет.
            if (preg_match('~\b(53[0-5]|55[0-7])\b|authentication|from address|sender address rejected~i', $info . ' ' . $msg)) {
                throw $e;
            }
            error_log("sendWithRetry attempt " . ($attempt + 1) . " failed: " . $msg . ' | ErrorInfo: ' . $info);
            $attempt++;
            if ($attempt <= $maxAttempts) {
                sleep($delays[$attempt - 1] ?? 30);
            }
        }
    }
    throw $lastErr ?? new Exception('sendWithRetry: exhausted retries');
}

/**
 * Поставить письмо в очередь pending_delayed_emails для отложенной отправки.
 * Обрабатывается cron/send-delayed-emails.php (раз в 5 минут).
 *
 * Используется, чтобы:
 *   - не слать «второй» транзакционный email тому же получателю back-to-back
 *     (Яндекс 360 классифицирует это как outbound-spam);
 *   - перезапланировать письмо после временного SMTP-сбоя без блокировки
 *     вебхука/AJAX-запроса.
 */
function scheduleDelayedEmail(string $emailType, int $userId, int $orderId, int $delayMinutes = 10, int $maxAttempts = 3): bool {
    global $db;
    try {
        $stmt = $db->prepare(
            "INSERT INTO pending_delayed_emails
                (email_type, user_id, order_id, send_after, max_attempts)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?)"
        );
        $stmt->execute([$emailType, $userId, $orderId, max(0, $delayMinutes), $maxAttempts]);
        return true;
    } catch (Exception $e) {
        error_log('scheduleDelayedEmail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Send payment success notification email with PDF attachments
 */
function sendPaymentSuccessEmail($userId, $orderId) {
    global $db;

    try {
        // Load order and user details
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj = new User($db);

        $order = $orderObj->getById($orderId);
        $user = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new Exception('Order or user not found');
        }

        // ⚠️ ВРЕМЕННЫЙ РЕЖИМ до 2026-05-11 (warmup info@fgos.pro в Яндекс 360):
        // HTML-шаблон payment_success режется Яндексом как СПАМ
        // (554 5.7.1 Message rejected under suspicion of SPAM).
        // Шлём упрощённый plain-text с magic-link на личный кабинет —
        // он проходит фильтр.
        // После 2026-05-11 вернуть HTML-вариант (см. git history этого блока).
        require_once __DIR__ . '/magic-link-helper.php';

        $mail = new PHPMailer(true);
        configureMailer($mail);
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);

        $cabinetUrl = generateMagicUrl((int)$userId, '/kabinet/', 14);
        $name  = trim((string)($user['full_name'] ?? ''));
        $greet = $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

        $textBody  = $greet . "\n\n";
        $textBody .= "Спасибо за оплату заказа {$order['order_number']} на сайте Педагогического портала «Каменный город» (fgos.pro).\n\n";
        $textBody .= "Все ваши документы (дипломы и сертификаты) сформированы и доступны в личном кабинете по ссылке:\n";
        $textBody .= $cabinetUrl . "\n\n";
        $textBody .= "Ссылка действует 14 дней и автоматически авторизует вас на сайте.\n\n";
        $textBody .= "Если возникнут вопросы — ответьте на это письмо или напишите на info@fgos.pro.\n\n";
        $textBody .= "С уважением,\nКоманда Педагогического портала «Каменный город»\nfgos.pro\n";

        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Документы по заказу ' . $order['order_number'] . ' (личный кабинет)';
        $mail->Body = $textBody;

        sendWithRetry($mail, [
            'email_type'      => 'payment',
            'touchpoint_code' => 'payment_success',
            'user_id'         => $userId,
            'recipient_email' => $user['email'],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], "Payment success email sent (plain-text fallback during Yandex warmup)");
        return true;

    } catch (Exception $e) {
        $errorInfo = isset($mail) && isset($mail->ErrorInfo) ? $mail->ErrorInfo : '';
        $detail    = trim($e->getMessage() . ($errorInfo ? ' | ErrorInfo: ' . $errorInfo : ''));
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Email failed: ' . $detail);
        // ВАЖНО: не ставим в pending_delayed_emails из catch — этим занимается
        // вызывающая сторона (webhook), а cron-обработчик очереди сам делает
        // backoff-ретрай. Иначе при провале из cron возникает каскад дубликатов.
        TelegramNotifier::instance()->alert(
            'smtp_send_failure',
            '[Email] Сбой отправки (payment_success)',
            [
                'email'     => $user['email'] ?? 'unknown',
                'order_id'  => $orderId,
                'error'     => $detail,
                'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : '',
            ],
            'critical'
        );
        // Пробрасываем исключение, чтобы вызывающий мог принять решение
        // (webhook ставит в очередь; cron видит exception и делает retry с backoff).
        throw $e;
    }
}

/**
 * Collect PDF attachments for order items
 * @param PDO $db Database connection
 * @param array $items Order items
 * @return array Attachments [['path' => ..., 'name' => ..., 'type' => ...], ...]
 */
function collectOrderAttachments($db, $items) {
    $attachments = [];

    foreach ($items as $item) {
        // Competition diplomas
        if (!empty($item['registration_id'])) {
            $stmt = $db->prepare("
                SELECT pdf_path, recipient_type FROM diplomas
                WHERE registration_id = ? AND recipient_type = 'participant'
                ORDER BY generated_at DESC LIMIT 1
            ");
            $stmt->execute([$item['registration_id']]);
            $diploma = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($diploma && !empty($diploma['pdf_path'])) {
                $pdfFullPath = BASE_PATH . '/uploads/diplomas/' . $diploma['pdf_path'];
                if (file_exists($pdfFullPath)) {
                    $title = !empty($item['competition_title']) ? $item['competition_title'] : 'конкурс';
                    $attachments[] = [
                        'path' => $pdfFullPath,
                        'name' => 'Диплом_' . mb_substr(preg_replace('/[^\w\d\-а-яёА-ЯЁ ]/u', '', $title), 0, 50) . '.pdf',
                        'type' => 'diploma',
                        'title' => $title
                    ];
                }
            }
        }

        // Publication certificates
        if (!empty($item['certificate_id'])) {
            $stmt = $db->prepare("SELECT pdf_path FROM publication_certificates WHERE id = ?");
            $stmt->execute([$item['certificate_id']]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($cert && !empty($cert['pdf_path'])) {
                $pdfFullPath = BASE_PATH . $cert['pdf_path'];
                if (file_exists($pdfFullPath)) {
                    $title = !empty($item['publication_title']) ? $item['publication_title'] : 'публикация';
                    $attachments[] = [
                        'path' => $pdfFullPath,
                        'name' => 'Свидетельство_' . mb_substr(preg_replace('/[^\w\d\-а-яёА-ЯЁ ]/u', '', $title), 0, 50) . '.pdf',
                        'type' => 'certificate',
                        'title' => $title
                    ];
                }
            }
        }

        // Webinar certificates
        if (!empty($item['webinar_certificate_id'])) {
            $stmt = $db->prepare("SELECT pdf_path FROM webinar_certificates WHERE id = ?");
            $stmt->execute([$item['webinar_certificate_id']]);
            $webCert = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($webCert && !empty($webCert['pdf_path'])) {
                $pdfFullPath = BASE_PATH . $webCert['pdf_path'];
                if (file_exists($pdfFullPath)) {
                    $title = !empty($item['webinar_title']) ? $item['webinar_title'] : 'вебинар';
                    $attachments[] = [
                        'path' => $pdfFullPath,
                        'name' => 'Сертификат_' . mb_substr(preg_replace('/[^\w\d\-а-яёА-ЯЁ ]/u', '', $title), 0, 50) . '.pdf',
                        'type' => 'webinar_certificate',
                        'title' => $title
                    ];
                }
            }
        }

        // Olympiad diplomas
        if (!empty($item['olympiad_registration_id'])) {
            $stmt = $db->prepare("
                SELECT pdf_path, recipient_type FROM olympiad_diplomas
                WHERE olympiad_registration_id = ? AND recipient_type = 'participant'
                ORDER BY generated_at DESC LIMIT 1
            ");
            $stmt->execute([$item['olympiad_registration_id']]);
            $olympDiploma = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($olympDiploma && !empty($olympDiploma['pdf_path'])) {
                $pdfFullPath = BASE_PATH . '/uploads/diplomas/' . $olympDiploma['pdf_path'];
                if (file_exists($pdfFullPath)) {
                    $title = !empty($item['olympiad_title']) ? $item['olympiad_title'] : 'олимпиада';
                    $attachments[] = [
                        'path' => $pdfFullPath,
                        'name' => 'Диплом_олимпиады_' . mb_substr(preg_replace('/[^\w\d\-а-яёА-ЯЁ ]/u', '', $title), 0, 50) . '.pdf',
                        'type' => 'olympiad_diploma',
                        'title' => $title
                    ];
                }
            }
        }
    }

    return $attachments;
}

/**
 * Build HTML email body for success with documents list
 * @param array $order Order data with items
 * @param array $user User data
 * @param array $attachments Attached PDF files
 * @return string HTML email body
 */
function buildSuccessEmailBody($order, $user, $attachments = []) {
    $siteUrl = SITE_URL;
    $cabinetLink = generateMagicUrl($user['id'], '/pages/cabinet.php?tab=diplomas');
    $orderNumber = htmlspecialchars($order['order_number']);
    $fullName = htmlspecialchars($user['full_name']);
    $finalAmount = number_format($order['final_amount'], 0, ',', ' ');
    $discountAmount = number_format($order['discount_amount'], 0, ',', ' ');
    $paidDate = date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at']));

    // Каждая строка таблицы — название документа + кнопка «Скачать», ведущая через
    // magic-auth (авто-логин по токену) на соответствующий /ajax/download-*-эндпоинт.
    // PDF не вкладываем в письмо — это давит спам-фильтры Яндекса/Mail.ru.
    $btnStyle = 'display:inline-block;background:#667eea;color:#ffffff;text-decoration:none;padding:6px 16px;border-radius:4px;font-size:13px;font-weight:600;white-space:nowrap;';
    $tdStyle  = 'padding:10px 12px;border-bottom:1px solid #eee;vertical-align:middle;';

    $itemsHtml = '';
    foreach ($order['items'] as $item) {
        $docType = null; $title = null; $extra = ''; $downloadUrl = null;

        if (!empty($item['registration_id']) && !empty($item['competition_title'])) {
            $docType = 'Диплом';
            $title = $item['competition_title'];
            $nomination = $item['nomination'] ?? '';
            if ($nomination !== '') $extra = ', номинация: ' . htmlspecialchars($nomination);
            $downloadUrl = generateMagicUrl((int)$user['id'], '/ajax/download-diploma.php?registration_id=' . (int)$item['registration_id'] . '&type=participant');
        } elseif (!empty($item['certificate_id']) && !empty($item['publication_title'])) {
            $docType = 'Свидетельство';
            $title = $item['publication_title'];
            $downloadUrl = generateMagicUrl((int)$user['id'], '/ajax/download-certificate.php?id=' . (int)$item['certificate_id']);
        } elseif (!empty($item['webinar_certificate_id']) && !empty($item['webinar_title'])) {
            $docType = 'Сертификат';
            $title = $item['webinar_title'];
            $downloadUrl = generateMagicUrl((int)$user['id'], '/ajax/download-webinar-certificate.php?id=' . (int)$item['webinar_certificate_id']);
        } elseif (!empty($item['olympiad_registration_id']) && !empty($item['olympiad_title'])) {
            $docType = 'Диплом олимпиады';
            $title = $item['olympiad_title'];
            if (!empty($item['olympiad_placement'])) $extra = ', ' . htmlspecialchars($item['olympiad_placement']) . ' место';
            $downloadUrl = generateMagicUrl((int)$user['id'], '/ajax/download-olympiad-diploma.php?id=' . (int)$item['olympiad_registration_id'] . '&type=participant');
        }

        if (!$docType) continue;
        $titleHtml = htmlspecialchars($title);
        $downloadCell = $downloadUrl
            ? "<a href=\"{$downloadUrl}\" style=\"{$btnStyle}\">Скачать</a>"
            : '<span style="color:#999;font-size:13px;">Готовится</span>';
        $itemsHtml .= "<tr>"
            . "<td style=\"{$tdStyle}\">{$docType}</td>"
            . "<td style=\"{$tdStyle}\"><strong>{$titleHtml}</strong>{$extra}</td>"
            . "<td style=\"{$tdStyle}text-align:right;\">{$downloadCell}</td>"
            . "</tr>";
    }

    // Discount row
    $discountHtml = '';
    if ($order['discount_amount'] > 0) {
        $discountHtml = "<li><strong>Скидка:</strong> {$discountAmount} &#8381;</li>";
    }

    // Webinar recommendation block (shown when order contains publication certificates)
    $webinarRecommendationHtml = '';
    $hasPublicationCert = false;
    foreach ($order['items'] as $item) {
        if (!empty($item['certificate_id'])) {
            $hasPublicationCert = true;
            break;
        }
    }
    if ($hasPublicationCert) {
        $webinarsLink = $siteUrl . '/vebinary?utm_source=email&utm_campaign=post-payment-webinar';
        $webinarRecommendationHtml = <<<WEBINAR
            <div style="background: linear-gradient(135deg, #E8F1FF 0%, #f0f7ff 100%); border-radius: 12px; padding: 25px; margin: 25px 0; border-left: 4px solid #0077FF;">
                <h3 style="margin: 0 0 12px 0; color: #0077FF; font-size: 18px;">Развивайтесь дальше!</h3>
                <p style="margin: 0 0 15px 0; color: #4A5568; font-size: 15px;">Посмотрите наши видеолекции для педагогов, пройдите тест и получите сертификат участника с указанием академических часов.</p>
                <center>
                    <a href="{$webinarsLink}" style="display: inline-block; background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%); color: #ffffff; text-decoration: none; padding: 14px 35px; border-radius: 50px; font-size: 15px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 119, 255, 0.3);">Смотреть видеолекции</a>
                </center>
            </div>
WEBINAR;
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ваши документы</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; margin: 0; padding: 0; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .header h1 { margin: 0 0 8px 0; font-size: 24px; }
        .header p { margin: 0; opacity: 0.9; font-size: 16px; }
        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
        .order-details { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
        .order-details h3 { margin-top: 0; color: #667eea; }
        .order-details ul { padding-left: 20px; }
        .docs-table { width: 100%; border-collapse: collapse; margin: 10px 0; }
        .docs-table th { text-align: left; padding: 8px 12px; background: #f0f0f0; border-bottom: 2px solid #667eea; color: #667eea; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; font-weight: bold; }
        .footer { text-align: center; color: #777; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Благодарим за покупку!</h1>
            <p>Заказ №{$orderNumber} успешно оплачен</p>
        </div>
        <div class="content">
            <p>Здравствуйте, <strong>{$fullName}</strong>!</p>

            <p>Ваш заказ успешно оплачен. Документы готовы — скачайте их по кнопкам ниже или откройте в личном кабинете.</p>

            <div class="order-details">
                <h3>Детали заказа:</h3>
                <ul>
                    <li><strong>Дата оплаты:</strong> {$paidDate}</li>
                    <li><strong>Сумма:</strong> {$finalAmount} &#8381;</li>
                    {$discountHtml}
                </ul>

                <h3>Ваши документы:</h3>
                <table class="docs-table">
                    <tr>
                        <th>Тип</th>
                        <th>Название</th>
                        <th style="text-align:right;">Файл</th>
                    </tr>
                    {$itemsHtml}
                </table>
            </div>

            <center>
                <a href="{$cabinetLink}" class="button">Открыть в личном кабинете</a>
            </center>

            {$webinarRecommendationHtml}

            <p style="color: #666; font-size: 14px;">Если у вас возникли вопросы, пожалуйста, свяжитесь с нами через форму обратной связи на сайте.</p>
        </div>
        <div class="footer">
            <p>С уважением,<br>Команда проекта &laquo;Каменный город&raquo;</p>
            <p style="font-size: 12px; color: #999;">Это автоматическое письмо, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Build plain text email body for success
 * @param array $order Order data with items
 * @param array $user User data
 * @param array $attachments Attached PDF files
 * @return string Plain text email body
 */
function buildSuccessEmailBodyText($order, $user, $attachments = []) {
    $orderNumber = $order['order_number'];
    $fullName = $user['full_name'];
    $finalAmount = number_format($order['final_amount'], 0, ',', ' ');
    $discountAmount = number_format($order['discount_amount'], 0, ',', ' ');
    $paidDate = date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at']));
    $cabinetLink = generateMagicUrl($user['id'], '/pages/cabinet.php');

    // Build items list for all document types
    $itemsText = '';
    foreach ($order['items'] as $item) {
        if (!empty($item['registration_id']) && !empty($item['competition_title'])) {
            $nomination = !empty($item['nomination']) ? ", номинация: {$item['nomination']}" : '';
            $itemsText .= "  - Диплом: {$item['competition_title']}{$nomination}\n";
        }
        if (!empty($item['certificate_id']) && !empty($item['publication_title'])) {
            $itemsText .= "  - Свидетельство: {$item['publication_title']}\n";
        }
        if (!empty($item['webinar_certificate_id']) && !empty($item['webinar_title'])) {
            $itemsText .= "  - Сертификат: {$item['webinar_title']}\n";
        }
        if (!empty($item['olympiad_registration_id']) && !empty($item['olympiad_title'])) {
            $placement = !empty($item['olympiad_placement']) ? " ({$item['olympiad_placement']} место)" : '';
            $itemsText .= "  - Диплом олимпиады: {$item['olympiad_title']}{$placement}\n";
        }
    }

    // PDF не вкладываем — ссылки на скачивание идут через кабинет (см. ниже).
    $attachText = '';

    // Discount line
    $discountLine = '';
    if ($order['discount_amount'] > 0) {
        $discountLine = "- Скидка: {$discountAmount} руб.\n";
    }

    // Webinar recommendation (text version)
    $webinarRecommendationText = '';
    $hasPublicationCert = false;
    foreach ($order['items'] as $item) {
        if (!empty($item['certificate_id'])) {
            $hasPublicationCert = true;
            break;
        }
    }
    if ($hasPublicationCert) {
        $webinarsLink = SITE_URL . '/vebinary';
        $webinarRecommendationText = "\nРазвивайтесь дальше!\nПосмотрите наши видеолекции для педагогов, пройдите тест и получите сертификат участника.\nСмотреть видеолекции: {$webinarsLink}\n";
    }

    return <<<TEXT
Благодарим за покупку! Заказ №{$orderNumber}

Здравствуйте, {$fullName}!

Ваш заказ успешно оплачен. Документы готовы — скачайте их в личном кабинете.

Детали заказа:
- Дата оплаты: {$paidDate}
- Сумма: {$finalAmount} руб.
{$discountLine}
Ваши документы:
{$itemsText}
Открыть в личном кабинете:
{$cabinetLink}
{$webinarRecommendationText}
Если у вас возникли вопросы, свяжитесь с нами через форму обратной связи на сайте.

С уважением,
Команда проекта "Каменный город"

---
Это автоматическое письмо, пожалуйста, не отвечайте на него.
TEXT;
}

/**
 * Отправить приветственное письмо о пожизненной скидке лояльности.
 * Вызывается один раз — сразу после первого успешного платежа пользователя.
 * Включает персональную рекомендацию продукта под аудиторию пользователя.
 */
function sendLifetimeDiscountGrantedEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';
        require_once __DIR__ . '/../classes/LoyaltyDiscount.php';

        $orderObj = new Order($db);
        $userObj = new User($db);

        $order = $orderObj->getById($orderId);
        $user = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new Exception('Order or user not found');
        }

        // ⚠️ ВРЕМЕННЫЙ РЕЖИМ до 2026-05-11 (warmup info@fgos.pro в Яндекс 360):
        // HTML-шаблон lifetime_discount_granted режется Яндексом как СПАМ
        // ("SMTP Error: data not accepted") — то же поведение, что было
        // у payment_success до коммита 24df167.
        // Шлём упрощённый plain-text с magic-link на личный кабинет —
        // он проходит фильтр.
        // После 2026-05-11 вернуть HTML-вариант (см. git history этого блока).
        require_once __DIR__ . '/magic-link-helper.php';

        $unsubscribeToken = base64_encode($user['email'] . ':' . substr(md5($user['email'] . SITE_URL), 0, 16));
        $unsubscribeUrl   = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);
        $cabinetUrl       = generateMagicUrl((int)$user['id'], '/kabinet/', 14);
        $cartPct          = (int)round(LoyaltyDiscount::RATE_CART * 100);
        $coursePct        = (int)round(LoyaltyDiscount::RATE_COURSE * 100);

        $name  = trim((string)($user['full_name'] ?? ''));
        $greet = $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

        $textBody  = $greet . "\n\n";
        $textBody .= "Спасибо за заказ {$order['order_number']} на сайте Педагогического портала «Каменный город» (fgos.pro).\n\n";
        $textBody .= "Мы активировали для вас пожизненную скидку лояльности:\n";
        $textBody .= "  • {$cartPct}% на конкурсы, олимпиады, видеолекции и публикации;\n";
        $textBody .= "  • {$coursePct}% на курсы повышения квалификации и переподготовки.\n\n";
        $textBody .= "Скидка применяется автоматически при следующих заказах из вашего личного кабинета:\n";
        $textBody .= $cabinetUrl . "\n\n";
        $textBody .= "Ссылка действует 14 дней и автоматически авторизует вас на сайте.\n\n";
        $textBody .= "Если возникнут вопросы — ответьте на это письмо или напишите на info@fgos.pro.\n\n";
        $textBody .= "С уважением,\nКоманда Педагогического портала «Каменный город»\nfgos.pro\n";

        $mail = new PHPMailer(true);
        configureMailer($mail);
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);
        $mail->isHTML(false);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Ваша пожизненная скидка активирована';
        $mail->Body    = $textBody;
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');

        sendWithRetry($mail, [
            'email_type'      => 'loyalty',
            'touchpoint_code' => 'lifetime_discount_granted',
            'user_id'         => $userId,
            'recipient_email' => $user['email'],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Lifetime discount email sent (plain-text fallback during Yandex warmup)');
        return true;

    } catch (Exception $e) {
        $errorInfo = isset($mail) && isset($mail->ErrorInfo) ? $mail->ErrorInfo : '';
        $detail    = trim($e->getMessage() . ($errorInfo ? ' | ErrorInfo: ' . $errorInfo : ''));
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Lifetime discount email failed: ' . $detail);
        // Пробрасываем — cron-обработчик очереди запишет осмысленный last_error
        // и сделает retry с backoff'ом.
        throw $e;
    }
}

/**
 * Построить псевдо-корзину для CartRecommendation из позиций заказа.
 * CartRecommendation ожидает элементы вида ['type' => ..., 'raw_data' => [...]].
 */
function buildRecommendationCartFromOrder(PDO $db, array $orderItems): array {
    $cart = [];
    foreach ($orderItems as $item) {
        if (!empty($item['registration_id'])) {
            $row = $db->prepare("SELECT competition_id FROM registrations WHERE id = ?");
            $row->execute([$item['registration_id']]);
            $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $cart[] = ['type' => 'registration', 'raw_data' => $data];
        } elseif (!empty($item['certificate_id'])) {
            $row = $db->prepare("SELECT publication_id FROM publication_certificates WHERE id = ?");
            $row->execute([$item['certificate_id']]);
            $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $cart[] = ['type' => 'certificate', 'raw_data' => $data];
        } elseif (!empty($item['webinar_certificate_id'])) {
            $row = $db->prepare("SELECT webinar_id FROM webinar_certificates WHERE id = ?");
            $row->execute([$item['webinar_certificate_id']]);
            $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $cart[] = ['type' => 'webinar_certificate', 'raw_data' => $data];
        } elseif (!empty($item['olympiad_registration_id'])) {
            $row = $db->prepare("SELECT olympiad_id FROM olympiad_registrations WHERE id = ?");
            $row->execute([$item['olympiad_registration_id']]);
            $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $cart[] = ['type' => 'olympiad_registration', 'raw_data' => $data];
        } elseif (!empty($item['course_enrollment_id'])) {
            $row = $db->prepare("SELECT course_id FROM course_enrollments WHERE id = ?");
            $row->execute([$item['course_enrollment_id']]);
            $data = $row->fetch(PDO::FETCH_ASSOC) ?: [];
            $cart[] = ['type' => 'course_enrollment', 'raw_data' => $data];
        }
    }
    return $cart;
}

function recommendationTypeLabel(string $type): string {
    return match($type) {
        'competition'            => 'Конкурс',
        'olympiad'               => 'Олимпиада',
        'webinar', 'webinar_browse', 'webinar_certificate', 'webinar_listing_cta' => 'Видеолекция',
        'publication', 'publication_certificate', 'publication_cta'               => 'Публикация',
        'course', 'course_cta'   => 'Курс',
        default                  => ''
    };
}

/**
 * Построить абсолютный URL для карточки рекомендации.
 */
function recommendationUrl(array $card): string {
    $type = $card['type'] ?? '';
    $slug = $card['slug'] ?? '';

    $buildDetail = function(string $section, string $slug) {
        if ($slug === '') {
            return SITE_URL . '/' . ltrim($section, '/');
        }
        return SITE_URL . '/' . trim($section, '/') . '/' . $slug . '/';
    };

    return match($type) {
        'competition'                                 => $buildDetail('konkursy', $slug),
        'olympiad'                                    => $buildDetail('olimpiady', $slug),
        'webinar', 'webinar_browse', 'webinar_certificate' => $buildDetail('vebinary', $slug),
        'webinar_listing_cta'                         => SITE_URL . '/vebinary/',
        'course'                                      => $buildDetail('kursy', $slug),
        'course_cta'                                  => SITE_URL . '/kursy/',
        'publication_certificate', 'publication'      => SITE_URL . '/zhurnal/',
        'publication_cta'                             => SITE_URL . '/opublikovat/',
        default                                       => SITE_URL . '/',
    };
}

function renderLifetimeDiscountEmail(array $data): string {
    // Экспортируем переменные в область видимости шаблона.
    extract($data, EXTR_SKIP);
    ob_start();
    include __DIR__ . '/email-templates/lifetime_discount_granted.php';
    return ob_get_clean();
}

function buildLifetimeDiscountTextBody(array $d): string {
    $lines = [];
    $lines[] = 'Здравствуйте, ' . $d['user_name'] . '!';
    $lines[] = '';
    $lines[] = 'Ваш заказ №' . $d['order_number'] . ' успешно оплачен — спасибо!';
    $lines[] = '';
    $lines[] = 'Теперь за вами закреплена пожизненная скидка:';
    $lines[] = '  • ' . $d['cart_discount_percent'] . '% на конкурсы, олимпиады, вебинары и публикации';
    $lines[] = '  • ' . $d['course_discount_percent'] . '% на курсы повышения квалификации и переподготовки';
    $lines[] = 'Скидки применяются автоматически.';
    $lines[] = '';
    if (!empty($d['has_recommendation'])) {
        $lines[] = 'Рекомендуем забрать со скидкой:';
        $lines[] = $d['recommended_title'];
        if (!empty($d['recommended_url'])) {
            $lines[] = $d['recommended_url'];
        }
    } else {
        $lines[] = 'Каталог: ' . $d['catalog_url'];
    }
    $lines[] = '';
    $lines[] = 'Личный кабинет: ' . $d['cabinet_url'];
    $lines[] = '';
    $lines[] = '---';
    $lines[] = 'Отписаться: ' . $d['unsubscribe_url'];
    return implode("\n", $lines);
}

/**
 * Send payment failure notification email
 */
function sendPaymentFailureEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj = new User($db);

        $order = $orderObj->getById($orderId);
        $user = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new Exception('Order or user not found');
        }

        $mail = new PHPMailer(true);
        configureMailer($mail);

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader('Проблема с оплатой заказа ' . $order['order_number'], 'UTF-8', 'B');

        $siteUrl = SITE_URL;
        $cartLink = generateMagicUrl($user['id'], '/pages/cart.php');
        $orderNumber = htmlspecialchars($order['order_number']);
        $fullName = htmlspecialchars($user['full_name']);

        $mail->Body = <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: #ff6b6b; color: white; padding: 20px; text-align: center; }
        .content { background: #f9f9f9; padding: 20px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>⚠️ Проблема с оплатой</h1>
        </div>
        <div class="content">
            <p>Здравствуйте, <strong>{$fullName}</strong>!</p>
            <p>К сожалению, платеж по заказу №{$orderNumber} не был завершен.</p>
            <p>Вы можете попробовать оплатить заказ снова или связаться с нашей службой поддержки.</p>
            <center>
                <a href="{$cartLink}" class="button">Попробовать снова</a>
            </center>
        </div>
    </div>
</body>
</html>
HTML;

        $mail->AltBody = "Здравствуйте, {$fullName}!\n\nК сожалению, платеж по заказу №{$orderNumber} не был завершен.\n\nВы можете попробовать оплатить заказ снова: {$cartLink}";

        require_once __DIR__ . '/../classes/EmailTracker.php';
        EmailTracker::prepareAndSend($mail, [
            'email_type'      => 'payment',
            'touchpoint_code' => 'payment_failure',
            'user_id'         => $userId,
            'recipient_email' => $user['email'],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment failure email sent');
        return true;

    } catch (Exception $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Failure email failed: ' . $e->getMessage());
        TelegramNotifier::instance()->alert(
            'smtp_send_failure',
            '[Email] Сбой отправки (payment_failure)',
            [
                'email'     => $user['email'] ?? 'unknown',
                'order_id'  => $orderId,
                'error'     => $e->getMessage(),
                'smtp_host' => defined('SMTP_HOST') ? SMTP_HOST : '',
            ],
            'critical'
        );
        return false;
    }
}

/**
 * Log email operations
 */
function logEmail($level, $email, $orderNumber, $message) {
    $logFile = BASE_PATH . '/logs/email.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$level} | {$email} | Order: {$orderNumber} | {$message}\n";

    // Ensure logs directory exists
    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}

/**
 * Test email configuration
 */
function testEmailConfig($testEmail) {
    try {
        $mail = new PHPMailer(true);
        configureMailer($mail);

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($testEmail);

        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader('Тест настройки email', 'UTF-8', 'B');
        $mail->Body = '<h1>Email работает!</h1><p>Настройка SMTP успешно завершена.</p>';
        $mail->AltBody = 'Email работает! Настройка SMTP успешно завершена.';

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Email test failed: ' . $e->getMessage());
        return false;
    }
}

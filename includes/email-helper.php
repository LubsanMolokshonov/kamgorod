<?php
/**
 * Email Helper Functions
 * Handles email notifications for payments using PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/magic-link-helper.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

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

    return $mail;
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

        // Collect PDF attachments for all order items
        $attachments = collectOrderAttachments($db, $order['items']);

        // Initialize and configure PHPMailer
        $mail = new PHPMailer(true);
        configureMailer($mail);

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = mb_encode_mimeheader('Ваши документы по заказу ' . $order['order_number'], 'UTF-8', 'B');

        // Build email body
        $htmlBody = buildSuccessEmailBody($order, $user, $attachments);
        $textBody = buildSuccessEmailBodyText($order, $user, $attachments);

        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        // Attach PDF documents
        foreach ($attachments as $att) {
            $mail->addAttachment($att['path'], $att['name']);
        }

        $mail->send();

        $attachCount = count($attachments);
        logEmail('SUCCESS', $user['email'], $order['order_number'], "Payment success email sent with {$attachCount} attachment(s)");
        return true;

    } catch (Exception $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Email failed: ' . $e->getMessage());
        return false;
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
    $cabinetLink = generateMagicUrl($user['id'], '/pages/cabinet.php');
    $orderNumber = htmlspecialchars($order['order_number']);
    $fullName = htmlspecialchars($user['full_name']);
    $finalAmount = number_format($order['final_amount'], 0, ',', ' ');
    $discountAmount = number_format($order['discount_amount'], 0, ',', ' ');
    $paidDate = date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at']));

    // Build items list for all document types
    $itemsHtml = '';
    foreach ($order['items'] as $item) {
        if (!empty($item['registration_id']) && !empty($item['competition_title'])) {
            $title = htmlspecialchars($item['competition_title']);
            $nomination = htmlspecialchars($item['nomination'] ?? '');
            $nominationText = $nomination ? ", номинация: {$nomination}" : '';
            $itemsHtml .= "<tr><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\">Диплом</td><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\"><strong>{$title}</strong>{$nominationText}</td></tr>";
        }
        if (!empty($item['certificate_id']) && !empty($item['publication_title'])) {
            $title = htmlspecialchars($item['publication_title']);
            $itemsHtml .= "<tr><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\">Свидетельство</td><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\"><strong>{$title}</strong></td></tr>";
        }
        if (!empty($item['webinar_certificate_id']) && !empty($item['webinar_title'])) {
            $title = htmlspecialchars($item['webinar_title']);
            $itemsHtml .= "<tr><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\">Сертификат</td><td style=\"padding: 8px 12px; border-bottom: 1px solid #eee;\"><strong>{$title}</strong></td></tr>";
        }
    }

    // Attachment notice
    $attachmentHtml = '';
    if (!empty($attachments)) {
        $attachCount = count($attachments);
        $attachmentHtml = <<<HTML
            <div style="background: #e8f5e9; padding: 16px 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #4caf50;">
                <p style="margin: 0 0 8px 0; font-weight: bold; color: #2e7d32;">К этому письму прикреплены ваши документы ({$attachCount} шт.):</p>
                <ul style="margin: 0; padding-left: 20px; color: #333;">
HTML;
        foreach ($attachments as $att) {
            $docType = match($att['type']) {
                'diploma' => 'Диплом',
                'certificate' => 'Свидетельство о публикации',
                'webinar_certificate' => 'Сертификат участника вебинара',
                default => 'Документ'
            };
            $attTitle = htmlspecialchars($att['title']);
            $attachmentHtml .= "<li>{$docType}: {$attTitle}</li>";
        }
        $attachmentHtml .= <<<HTML
                </ul>
            </div>
HTML;
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

            <p>Спасибо, что выбрали наш портал! Ваш заказ успешно оплачен, и мы рады отправить вам ваши документы.</p>

            {$attachmentHtml}

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
                    </tr>
                    {$itemsHtml}
                </table>
            </div>

            <p>Все документы также доступны для скачивания в вашем личном кабинете.</p>

            <center>
                <a href="{$cabinetLink}" class="button">Перейти в личный кабинет</a>
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
    }

    // Attachment notice
    $attachText = '';
    if (!empty($attachments)) {
        $attachText = "\nК этому письму прикреплены ваши документы (" . count($attachments) . " шт.).\n";
    }

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

Спасибо, что выбрали наш портал! Ваш заказ успешно оплачен.
{$attachText}
Детали заказа:
- Дата оплаты: {$paidDate}
- Сумма: {$finalAmount} руб.
{$discountLine}
Ваши документы:
{$itemsText}
Все документы также доступны для скачивания в личном кабинете:
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

        $mail->send();

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment failure email sent');
        return true;

    } catch (Exception $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Failure email failed: ' . $e->getMessage());
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

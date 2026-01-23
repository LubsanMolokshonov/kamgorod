<?php
/**
 * Email Helper Functions
 * Handles email notifications for payments using PHPMailer
 */

require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

/**
 * Send payment success notification email
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

        // Initialize PHPMailer
        $mail = new PHPMailer(true);

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Оплата заказа ' . $order['order_number'] . ' успешно завершена';

        // Build email body
        $htmlBody = buildSuccessEmailBody($order, $user);
        $textBody = buildSuccessEmailBodyText($order, $user);

        $mail->Body = $htmlBody;
        $mail->AltBody = $textBody;

        $mail->send();

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment success email sent');
        return true;

    } catch (Exception $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Email failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Build HTML email body for success
 */
function buildSuccessEmailBody($order, $user) {
    $siteUrl = SITE_URL;
    $orderNumber = htmlspecialchars($order['order_number']);
    $fullName = htmlspecialchars($user['full_name']);
    $finalAmount = number_format($order['final_amount'], 0, ',', ' ');
    $discountAmount = number_format($order['discount_amount'], 0, ',', ' ');
    $paidDate = date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at']));

    $itemsHtml = '';
    foreach ($order['items'] as $item) {
        $competitionTitle = htmlspecialchars($item['competition_title']);
        $nomination = htmlspecialchars($item['nomination']);
        $itemsHtml .= "<li><strong>{$competitionTitle}</strong>, номинация: {$nomination}</li>";
    }

    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Оплата заказа</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0; }
        .content { background: #f9f9f9; padding: 30px; border-radius: 0 0 8px 8px; }
        .order-details { background: white; padding: 20px; margin: 20px 0; border-radius: 8px; border-left: 4px solid #667eea; }
        .order-details h3 { margin-top: 0; color: #667eea; }
        .order-details ul { padding-left: 20px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin: 20px 0; }
        .footer { text-align: center; color: #777; font-size: 14px; margin-top: 30px; padding-top: 20px; border-top: 1px solid #ddd; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>✅ Оплата успешно завершена!</h1>
        </div>
        <div class="content">
            <p>Здравствуйте, <strong>{$fullName}</strong>!</p>

            <p>Ваш заказ <strong>№{$orderNumber}</strong> успешно оплачен.</p>

            <div class="order-details">
                <h3>Детали заказа:</h3>
                <ul>
                    <li><strong>Дата оплаты:</strong> {$paidDate}</li>
                    <li><strong>Сумма к оплате:</strong> {$finalAmount} ₽</li>
                    <li><strong>Скидка:</strong> {$discountAmount} ₽</li>
                </ul>

                <h3>Участие в конкурсах:</h3>
                <ul>
                    {$itemsHtml}
                </ul>
            </div>

            <p>Ваши дипломы будут доступны в личном кабинете после подведения итогов конкурса.</p>

            <center>
                <a href="{$siteUrl}/pages/cabinet.php" class="button">Перейти в личный кабинет</a>
            </center>

            <p>Если у вас возникли вопросы, пожалуйста, свяжитесь с нами через форму обратной связи на сайте.</p>
        </div>
        <div class="footer">
            <p>С уважением,<br>Команда проекта "Каменный город"</p>
            <p style="font-size: 12px; color: #999;">Это автоматическое письмо, пожалуйста, не отвечайте на него.</p>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Build plain text email body for success
 */
function buildSuccessEmailBodyText($order, $user) {
    $orderNumber = $order['order_number'];
    $fullName = $user['full_name'];
    $finalAmount = number_format($order['final_amount'], 0, ',', ' ');
    $discountAmount = number_format($order['discount_amount'], 0, ',', ' ');
    $paidDate = date('d.m.Y H:i', strtotime($order['paid_at'] ?? $order['created_at']));
    $siteUrl = SITE_URL;

    $itemsText = '';
    foreach ($order['items'] as $item) {
        $itemsText .= "  - {$item['competition_title']}, номинация: {$item['nomination']}\n";
    }

    return <<<TEXT
Оплата заказа {$orderNumber} успешно завершена

Здравствуйте, {$fullName}!

Ваш заказ №{$orderNumber} успешно оплачен.

Детали заказа:
- Дата оплаты: {$paidDate}
- Сумма к оплате: {$finalAmount} ₽
- Скидка: {$discountAmount} ₽

Участие в конкурсах:
{$itemsText}

Ваши дипломы будут доступны в личном кабинете после подведения итогов конкурса.

Перейти в личный кабинет: {$siteUrl}/pages/cabinet.php

Если у вас возникли вопросы, пожалуйста, свяжитесь с нами через форму обратной связи на сайте.

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

        // SMTP Configuration
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($user['email'], $user['full_name']);

        // Content
        $mail->isHTML(true);
        $mail->Subject = 'Проблема с оплатой заказа ' . $order['order_number'];

        $siteUrl = SITE_URL;
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
                <a href="{$siteUrl}/pages/cart.php" class="button">Попробовать снова</a>
            </center>
        </div>
    </div>
</body>
</html>
HTML;

        $mail->AltBody = "Здравствуйте, {$fullName}!\n\nК сожалению, платеж по заказу №{$orderNumber} не был завершен.\n\nВы можете попробовать оплатить заказ снова: {$siteUrl}/pages/cart.php";

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

        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->SMTPAuth = true;
        $mail->Username = SMTP_USERNAME;
        $mail->Password = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = SMTP_PORT;
        $mail->CharSet = 'UTF-8';

        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($testEmail);

        $mail->isHTML(true);
        $mail->Subject = 'Тест настройки email';
        $mail->Body = '<h1>Email работает!</h1><p>Настройка SMTP успешно завершена.</p>';
        $mail->AltBody = 'Email работает! Настройка SMTP успешно завершена.';

        $mail->send();
        return true;

    } catch (Exception $e) {
        error_log('Email test failed: ' . $e->getMessage());
        return false;
    }
}

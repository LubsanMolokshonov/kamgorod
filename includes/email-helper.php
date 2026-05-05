<?php
/**
 * Email Helper Functions
 *
 * Все исходящие письма идут через Unisender Go (см. classes/EmailDispatcher.php).
 * Этот файл — только тонкая прослойка для транзакционных писем оплаты и
 * хелперы дросселирования/очереди отложенной отправки.
 */

require_once __DIR__ . '/magic-link-helper.php';
require_once __DIR__ . '/../classes/TelegramNotifier.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

/**
 * Был ли получателю отправлен какой-либо учётный email за последние N минут.
 * Используется для дросселирования chain-кронов: если человек уже получал
 * письмо недавно — пропускаем (оставляем в pending), чтобы не выглядеть
 * спам-ботом перед фильтрами провайдеров получателя (Gmail/Mail.ru/Яндекс).
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
 * Превышен ли суточный лимит chain-писем для получателя.
 * Используется для жёсткого защитного потолка на случай, когда после простоя
 * Unisender'а в очереди скапливается несколько писем разных каналов на одного
 * пользователя — чтобы они не приехали залпом. Письмо при срабатывании
 * остаётся в pending и подхватится следующим прогоном, когда окно сдвинется.
 */
function recipientReachedDailyCap(PDO $pdo, string $email, int $cap): bool {
    if ($cap <= 0 || $email === '') return false;
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM email_events
         WHERE recipient_email = ? AND sent_at >= NOW() - INTERVAL 24 HOUR"
    );
    $stmt->execute([$email]);
    return ((int)$stmt->fetchColumn()) >= $cap;
}

/**
 * Поставить письмо в очередь pending_delayed_emails для отложенной отправки.
 * Обрабатывается cron/send-delayed-emails.php (раз в 5 минут).
 *
 * Используется, чтобы:
 *   - не слать «второй» транзакционный email тому же получателю back-to-back
 *     (гигиена inbox, мягче для антиспам-фильтров);
 *   - перезапланировать письмо после временного сбоя Unisender без блокировки
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
    } catch (\Throwable $e) {
        error_log('scheduleDelayedEmail failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Транзакционка: подтверждение успешной оплаты заказа (magic-link на ЛК).
 * PDF-вложения не используются — все документы доступны в кабинете.
 */
function sendPaymentSuccessEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $cabinetUrl = generateMagicUrl((int)$userId, '/kabinet/', 14);
        $htmlBody = buildSuccessEmailBody($order, $user, $cabinetUrl);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Документы по заказу ' . $order['order_number'] . ' (личный кабинет)',
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'payment',
                'touchpoint_code' => 'payment_success',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment success email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        $detail = $e->getMessage();
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Email failed: ' . $detail);
        // Не ставим в pending_delayed_emails из catch — этим занимается
        // вызывающая сторона (webhook), а cron-обработчик очереди сам делает
        // backoff-ретрай. Иначе при провале из cron возникает каскад дубликатов.
        TelegramNotifier::instance()->alert(
            'unisender_send_failure',
            '[Email] Сбой отправки (payment_success)',
            [
                'email'    => $user['email'] ?? 'unknown',
                'order_id' => $orderId,
                'error'    => $detail,
                'provider' => 'unisender_go',
            ],
            'critical'
        );
        throw $e;
    }
}

/**
 * Транзакционка: уведомление о пожизненной скидке лояльности после оплаты.
 */
function sendLifetimeDiscountGrantedEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';
        require_once __DIR__ . '/../classes/LoyaltyDiscount.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $unsubscribeToken = base64_encode($user['email'] . ':' . substr(md5($user['email'] . SITE_URL), 0, 16));
        $unsubscribeUrl   = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);
        $cabinetUrl       = generateMagicUrl((int)$user['id'], '/kabinet/', 14);
        $cartPct          = (int)round(LoyaltyDiscount::RATE_CART * 100);
        $coursePct        = (int)round(LoyaltyDiscount::RATE_COURSE * 100);

        $htmlBody = buildLifetimeDiscountEmailBody($order, $user, $cabinetUrl, $unsubscribeUrl, $cartPct, $coursePct);

        EmailDispatcher::send([
            'to_email'        => $user['email'],
            'to_name'         => $user['full_name'],
            'subject'         => 'Ваша пожизненная скидка активирована',
            'html'            => $htmlBody,
            'unsubscribe_url' => $unsubscribeUrl,
            'meta'            => [
                // 'loyalty' нет в ENUM email_events.email_type — используем 'payment'
                // (это уведомление об оплате с активацией скидки).
                'email_type'      => 'payment',
                'touchpoint_code' => 'lifetime_discount_granted',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Lifetime discount email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Lifetime discount email failed: ' . $e->getMessage());
        throw $e;
    }
}

/**
 * Транзакционка: уведомление о неудачной оплате с magic-link на повтор корзины.
 */
function sendPaymentFailureEmail($userId, $orderId) {
    global $db;

    try {
        require_once __DIR__ . '/../classes/Order.php';
        require_once __DIR__ . '/../classes/User.php';

        $orderObj = new Order($db);
        $userObj  = new User($db);
        $order = $orderObj->getById($orderId);
        $user  = $userObj->getById($userId);

        if (!$order || !$user) {
            throw new \Exception('Order or user not found');
        }

        $cartLink    = generateMagicUrl($user['id'], '/pages/cart.php');
        $orderNumber = $order['order_number'];

        $htmlBody = buildFailureEmailBody($order, $user, $cartLink);

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Проблема с оплатой заказа ' . $orderNumber,
            'html'     => $htmlBody,
            'meta'     => [
                'email_type'      => 'payment',
                'touchpoint_code' => 'payment_failure',
                'user_id'         => $userId,
            ],
        ]);

        logEmail('SUCCESS', $user['email'], $order['order_number'], 'Payment failure email sent via Unisender');
        return true;

    } catch (\Throwable $e) {
        logEmail('ERROR', $user['email'] ?? 'unknown', $orderId, 'Failure email failed: ' . $e->getMessage());
        TelegramNotifier::instance()->alert(
            'unisender_send_failure',
            '[Email] Сбой отправки (payment_failure)',
            [
                'email'    => $user['email'] ?? 'unknown',
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
                'provider' => 'unisender_go',
            ],
            'critical'
        );
        return false;
    }
}

/**
 * Логирование email-операций в logs/email.log (для grep/аудита).
 */
function logEmail($level, $email, $orderNumber, $message) {
    $logFile = BASE_PATH . '/logs/email.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMessage = "[{$timestamp}] {$level} | {$email} | Order: {$orderNumber} | {$message}\n";

    $logDir = dirname($logFile);
    if (!file_exists($logDir)) {
        mkdir($logDir, 0755, true);
    }

    error_log($logMessage, 3, $logFile);
}

/**
 * HTML-обёртка для транзакционных писем оплаты.
 * Минимальный inline-CSS — большинство почтовиков (Gmail, Mail.ru, Яндекс) корректно рендерят.
 */
function renderTransactionalEmailLayout(string $headerTitle, string $headerSubtitle, string $contentHtml): string {
    $year = date('Y');
    return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$headerTitle}</title>
</head>
<body style="margin:0;padding:0;background:#f5f7fb;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;line-height:1.6;color:#333;">
    <div style="max-width:600px;margin:0 auto;padding:20px;">
        <div style="background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:30px;text-align:center;border-radius:8px 8px 0 0;">
            <h1 style="margin:0 0 8px 0;font-size:24px;font-weight:600;">{$headerTitle}</h1>
            <p style="margin:0;opacity:0.9;font-size:16px;">{$headerSubtitle}</p>
        </div>
        <div style="background:#ffffff;padding:30px;border-radius:0 0 8px 8px;border:1px solid #e9ecf3;border-top:none;">
            {$contentHtml}
        </div>
        <div style="text-align:center;color:#777;font-size:13px;margin-top:25px;padding-top:15px;">
            <p style="margin:0 0 6px 0;">С уважением,<br>Команда Педагогического портала «Каменный город»</p>
            <p style="margin:0;font-size:12px;color:#999;">© {$year} fgos.pro · автоматическое уведомление</p>
        </div>
    </div>
</body>
</html>
HTML;
}

function buildSuccessEmailBody(array $order, array $user, string $cabinetUrl): string {
    $orderNumber = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName    = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet       = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cabinetUrlEsc = htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cabinetUrlEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(102,126,234,0.35);\">Открыть личный кабинет</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Спасибо за оплату заказа <strong>№{$orderNumber}</strong>. Все ваши документы (дипломы, сертификаты, свидетельства) сформированы и доступны в личном кабинете.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:0 0 12px 0;color:#5a5f6b;font-size:14px;">Ссылка действует 14 дней и автоматически авторизует вас на сайте — вводить пароль не нужно.</p>
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Если возникнут вопросы — ответьте на это письмо или напишите на <a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a>.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Благодарим за покупку!',
        'Заказ №' . $orderNumber . ' успешно оплачен',
        $content
    );
}

function buildLifetimeDiscountEmailBody(array $order, array $user, string $cabinetUrl, string $unsubscribeUrl, int $cartPct, int $coursePct): string {
    $orderNumber   = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName      = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet         = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cabinetUrlEsc = htmlspecialchars($cabinetUrl, ENT_QUOTES, 'UTF-8');
    $unsubEsc      = htmlspecialchars($unsubscribeUrl, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cabinetUrlEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#22c55e 0%,#16a34a 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(34,197,94,0.3);\">Перейти в кабинет</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">Спасибо за заказ <strong>№{$orderNumber}</strong>. Мы активировали для вас <strong>пожизненную скидку лояльности</strong> на все следующие покупки:</p>
        <ul style="margin:0 0 22px 0;padding-left:22px;color:#333;">
            <li style="margin-bottom:8px;"><strong>{$cartPct}%</strong> — на конкурсы, олимпиады, видеолекции и публикации</li>
            <li><strong>{$coursePct}%</strong> — на курсы повышения квалификации и переподготовки</li>
        </ul>
        <p style="margin:0 0 22px 0;color:#5a5f6b;">Скидка применяется автоматически при оформлении следующих заказов в личном кабинете.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:0;color:#5a5f6b;font-size:13px;">Ссылка действует 14 дней и автоматически авторизует вас на сайте.</p>
        <p style="margin:24px 0 0 0;font-size:12px;color:#9aa0ad;text-align:center;">Если вы не хотите получать такие уведомления — <a href="{$unsubEsc}" style="color:#9aa0ad;text-decoration:underline;">отписаться</a>.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Скидка лояльности активирована',
        'Заказ №' . $orderNumber,
        $content
    );
}

function buildFailureEmailBody(array $order, array $user, string $cartLink): string {
    $orderNumber = htmlspecialchars($order['order_number'] ?? '', ENT_QUOTES, 'UTF-8');
    $fullName    = htmlspecialchars(trim((string)($user['full_name'] ?? '')), ENT_QUOTES, 'UTF-8');
    $greet       = $fullName !== '' ? "Здравствуйте, <strong>{$fullName}</strong>!" : 'Здравствуйте!';
    $cartLinkEsc = htmlspecialchars($cartLink, ENT_QUOTES, 'UTF-8');

    $btn = "<a href=\"{$cartLinkEsc}\" style=\"display:inline-block;background:linear-gradient(135deg,#ef4444 0%,#dc2626 100%);color:#fff;text-decoration:none;padding:14px 36px;border-radius:50px;font-size:15px;font-weight:600;box-shadow:0 4px 14px rgba(239,68,68,0.3);\">Повторить оплату</a>";

    $content = <<<HTML
        <p style="margin:0 0 18px 0;font-size:16px;">{$greet}</p>
        <p style="margin:0 0 18px 0;">К сожалению, платёж по заказу <strong>№{$orderNumber}</strong> не был завершён. Это могло произойти из-за обрыва соединения, недостаточного баланса на карте или ограничений банка.</p>
        <p style="margin:0 0 22px 0;">Попробовать оплатить ещё раз можно по ссылке ниже — она автоматически авторизует вас на сайте, заполненная корзина уже ждёт.</p>
        <div style="text-align:center;margin:28px 0;">{$btn}</div>
        <p style="margin:18px 0 0 0;color:#5a5f6b;font-size:14px;">Если оплатить повторно не получается — ответьте на это письмо или напишите на <a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a>, мы поможем разобраться.</p>
HTML;

    return renderTransactionalEmailLayout(
        'Оплата не прошла',
        'Заказ №' . $orderNumber,
        $content
    );
}

/**
 * Тест конфигурации Unisender Go: отправляет тестовое HTML-письмо.
 */
function testEmailConfig($testEmail) {
    try {
        EmailDispatcher::send([
            'to_email' => $testEmail,
            'subject'  => 'Тест настройки email',
            'html'     => '<h1>Email работает!</h1><p>Настройка Unisender Go успешно завершена.</p>',
            'text'     => 'Email работает! Настройка Unisender Go успешно завершена.',
            'meta'     => ['email_type' => 'other', 'touchpoint_code' => 'config_test'],
        ]);
        return true;
    } catch (\Throwable $e) {
        error_log('Email test failed: ' . $e->getMessage());
        return false;
    }
}

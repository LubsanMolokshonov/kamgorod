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
 * PDF-вложения сейчас не используются — все документы доступны в кабинете.
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
        $name  = trim((string)($user['full_name'] ?? ''));
        $greet = $name !== '' ? "Здравствуйте, {$name}!" : 'Здравствуйте!';

        $textBody  = $greet . "\n\n";
        $textBody .= "Спасибо за оплату заказа {$order['order_number']} на сайте Педагогического портала «Каменный город» (fgos.pro).\n\n";
        $textBody .= "Все ваши документы (дипломы и сертификаты) сформированы и доступны в личном кабинете по ссылке:\n";
        $textBody .= $cabinetUrl . "\n\n";
        $textBody .= "Ссылка действует 14 дней и автоматически авторизует вас на сайте.\n\n";
        $textBody .= "Если возникнут вопросы — ответьте на это письмо или напишите на info@fgos.pro.\n\n";
        $textBody .= "С уважением,\nКоманда Педагогического портала «Каменный город»\nfgos.pro\n";

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Документы по заказу ' . $order['order_number'] . ' (личный кабинет)',
            'text'     => $textBody,
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

        EmailDispatcher::send([
            'to_email'        => $user['email'],
            'to_name'         => $user['full_name'],
            'subject'         => 'Ваша пожизненная скидка активирована',
            'text'            => $textBody,
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
        $fullName    = $user['full_name'];

        $textBody = "Здравствуйте, {$fullName}!\n\n"
            . "К сожалению, платёж по заказу №{$orderNumber} не был завершён.\n\n"
            . "Попробовать оплатить ещё раз можно по ссылке (она автоматически авторизует вас на сайте):\n"
            . $cartLink . "\n\n"
            . "Если возникнут вопросы — ответьте на это письмо или напишите на info@fgos.pro.\n\n"
            . "С уважением,\nКоманда Педагогического портала «Каменный город»\nfgos.pro\n";

        EmailDispatcher::send([
            'to_email' => $user['email'],
            'to_name'  => $user['full_name'],
            'subject'  => 'Проблема с оплатой заказа ' . $orderNumber,
            'text'     => $textBody,
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

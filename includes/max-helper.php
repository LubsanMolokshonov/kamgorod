<?php
/**
 * MAX Helper — транзакционное уведомление в мессенджер «Макс» при оплате мероприятия.
 *
 * Отправка идёт через ChatPush (см. classes/ChatpushClient.php). Вызывается из
 * api/webhook/yookassa.php после успешной оплаты. Доставка в Макс не должна ломать
 * webhook: все ошибки ловятся внутри, наружу не пробрасываются.
 *
 * Каждая отправка журналируется в таблицу max_notifications (миграция 154).
 * UNIQUE(order_id) гарантирует ровно одно сообщение на заказ даже при повторных событиях.
 */

require_once __DIR__ . '/magic-link-helper.php';
require_once __DIR__ . '/../classes/ChatpushClient.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/Database.php';

/**
 * Отправить уведомление об оплате в Макс.
 *
 * Уведомляем только о мероприятиях: конкурс / олимпиада / публикация / вебинар.
 * Курсы, токены, подписки — пропускаем.
 *
 * @param int   $userId     ID пользователя.
 * @param array $order      Заказ (поля orders.* + email/full_name из getByPaymentId).
 * @param array $orderItems Позиции заказа (из Order::getOrderItems).
 * @return string Статус: 'sent' | 'skipped' | 'failed'.
 */
function sendPaymentMaxNotification(int $userId, array $order, array $orderItems): string {
    global $db;

    // Kill-switch / нет токена.
    if (!defined('CHATPUSH_ACTIVE') || !CHATPUSH_ACTIVE || CHATPUSH_API_TOKEN === '') {
        return 'skipped';
    }

    $orderId = (int)($order['id'] ?? 0);
    if ($orderId <= 0) {
        return 'skipped';
    }

    try {
        $dbh = new Database($db);

        // Дедуп: одно сообщение на заказ.
        $already = $dbh->queryOne("SELECT id FROM max_notifications WHERE order_id = ?", [$orderId]);
        if ($already) {
            return 'skipped';
        }

        // Определить продукт-мероприятие по позициям заказа (первый подходящий).
        $title = maxResolveEventTitle($orderItems);
        if ($title === null) {
            // Заказ без мероприятий (курс/токены/подписка) — не уведомляем, но фиксируем skip
            // под UNIQUE(order_id), чтобы не пересчитывать на повторных событиях.
            maxLogNotification($dbh, $orderId, $userId, null, null, null, 'skipped', null, null, 'no event item');
            return 'skipped';
        }

        // Телефон берём из users.phone (в orders его нет), нормализуем.
        $userObj = new User($db);
        $user    = $userObj->getById($userId);
        $phone   = ChatpushClient::normalizePhone($user['phone'] ?? null);
        $email   = $order['email'] ?? ($user['email'] ?? '');

        if ($phone === null) {
            maxLogNotification($dbh, $orderId, $userId, null, $title, null, 'skipped', null, null, 'no valid phone');
            return 'skipped';
        }

        // Текст сообщения: благодарность + чек на email + magic-link на кабинет (авто-авторизация).
        $magicUrl = generateMagicUrl($userId, '/kabinet/', 14);
        $amount   = (float)($order['final_amount'] ?? 0);
        $amountStr = rtrim(rtrim(number_format($amount, 2, '.', ' '), '0'), '.');

        $emailPart = $email !== '' ? " Чек отправлен на вашу почту {$email}." : ' Чек отправлен на вашу почту.';
        $text = "Спасибо! Вы оплатили «{$title}» на сумму {$amountStr} ₽."
              . $emailPart
              . " Документы и диплом — в личном кабинете: {$magicUrl}";

        $client = new ChatpushClient();
        $result = $client->send($phone, $text);

        $status = $result['success'] ? 'sent' : 'failed';
        maxLogNotification(
            $dbh, $orderId, $userId, $phone, $title, $text,
            $status, $result['http_code'] ?? null, $result['response'] ?? null, $result['error'] ?? null
        );

        // Дублируем в ленту переписки (для дашборда /admin/max/).
        maxLogOutboundThread(
            $db, $phone, $userId, $orderId, $text,
            $status, $result['http_code'] ?? null, $result['response'] ?? null, $result['error'] ?? null,
            $result['provider_message_id'] ?? null
        );

        return $status;

    } catch (\Throwable $e) {
        error_log('sendPaymentMaxNotification error: ' . $e->getMessage());
        return 'failed';
    }
}

/**
 * Отправить уведомление об активации подписки в Макс.
 *
 * НЕ ИСПОЛЬЗУЕТСЯ (решение от 2026-06-24): покупателям подписок в «Макс» не пишем,
 * только покупателям мероприятий. Вызов убран из api/webhook/yookassa.php.
 * Функция оставлена на случай возврата к рассылке подписчикам.
 *
 * Сообщение отличается от уведомления о мероприятии (другой текст, ведём в раздел подписки).
 *
 * @param int   $userId         ID пользователя.
 * @param array $order          Заказ подписки (orders.* + email из getByPaymentId).
 * @param int   $subscriptionId ID активированной подписки (user_subscriptions.id).
 * @return string Статус: 'sent' | 'skipped' | 'failed'.
 */
function sendSubscriptionMaxNotification(int $userId, array $order, int $subscriptionId): string {
    global $db;

    if (!defined('CHATPUSH_ACTIVE') || !CHATPUSH_ACTIVE || CHATPUSH_API_TOKEN === '') {
        return 'skipped';
    }

    $orderId = (int)($order['id'] ?? 0);
    if ($orderId <= 0) {
        return 'skipped';
    }

    try {
        $dbh = new Database($db);

        // Дедуп: одно сообщение на заказ.
        if ($dbh->queryOne("SELECT id FROM max_notifications WHERE order_id = ?", [$orderId])) {
            return 'skipped';
        }

        $sub = $dbh->queryOne(
            "SELECT us.period, us.expires_at, p.name AS plan_name
               FROM user_subscriptions us
               JOIN subscription_plans p ON p.id = us.plan_id
              WHERE us.id = ?",
            [$subscriptionId]
        );
        if (!$sub) {
            maxLogNotification($dbh, $orderId, $userId, null, null, null, 'skipped', null, null, 'subscription not found');
            return 'skipped';
        }

        $planName = (string)$sub['plan_name'];
        $title    = 'Подписка «' . $planName . '»';

        $userObj = new User($db);
        $user    = $userObj->getById($userId);
        $phone   = ChatpushClient::normalizePhone($user['phone'] ?? null);
        $email   = $order['email'] ?? ($user['email'] ?? '');

        if ($phone === null) {
            maxLogNotification($dbh, $orderId, $userId, null, $title, null, 'skipped', null, null, 'no valid phone');
            return 'skipped';
        }

        $magicUrl  = generateMagicUrl($userId, '/podpiska/', 14);
        $periodStr = ($sub['period'] === 'yearly' || $sub['period'] === 'annual') ? 'год' : 'месяц';
        $untilStr  = !empty($sub['expires_at']) ? date('d.m.Y', strtotime((string)$sub['expires_at'])) : '';

        $emailPart  = $email !== '' ? " Чек отправлен на вашу почту {$email}." : ' Чек отправлен на вашу почту.';
        $untilPart  = $untilStr !== '' ? " Действует до {$untilStr}." : '';
        $text = "Спасибо! Подписка «{$planName}» ({$periodStr}) активирована.{$untilPart}"
              . $emailPart
              . " Управление подпиской — в личном кабинете: {$magicUrl}";

        $client = new ChatpushClient();
        $result = $client->send($phone, $text);

        $status = $result['success'] ? 'sent' : 'failed';
        maxLogNotification(
            $dbh, $orderId, $userId, $phone, $title, $text,
            $status, $result['http_code'] ?? null, $result['response'] ?? null, $result['error'] ?? null
        );

        return $status;

    } catch (\Throwable $e) {
        error_log('sendSubscriptionMaxNotification error: ' . $e->getMessage());
        return 'failed';
    }
}

/**
 * Найти название мероприятия в позициях заказа.
 * Учитываем только конкурс/олимпиаду/публикацию/вебинар. Возвращает null, если их нет.
 */
function maxResolveEventTitle(array $orderItems): ?string {
    foreach ($orderItems as $item) {
        if (!empty($item['competition_id']) && !empty($item['competition_title'])) {
            return (string)$item['competition_title'];
        }
        if (!empty($item['olympiad_id']) && !empty($item['olympiad_title'])) {
            return (string)$item['olympiad_title'];
        }
        if (!empty($item['publication_id']) && !empty($item['publication_title'])) {
            return (string)$item['publication_title'];
        }
        if (!empty($item['webinar_id']) && !empty($item['webinar_title'])) {
            return (string)$item['webinar_title'];
        }
    }
    return null;
}

/**
 * Записать исходящее системное уведомление в ленту переписки max_messages.
 * Лента используется дашбордом /admin/max/ (вместе с входящими и ответами ИИ/менеджера).
 * Best-effort: ошибки не пробрасываем, max_notifications остаётся основным журналом отправки.
 *
 * @param PDO $pdo Глобальный PDO ($db).
 */
function maxLogOutboundThread(
    PDO $pdo, string $phone, ?int $userId, int $orderId, ?string $text,
    string $status, ?int $httpCode, ?string $response, ?string $error, ?string $providerMessageId = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO max_messages
             (phone, user_id, direction, author, `text`, `status`, http_code, provider_response, error, order_id, provider_message_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $phone,
            $userId,
            'out',
            'system',
            $text,
            $status,
            $httpCode,
            $response !== null ? mb_substr($response, 0, 2000) : null,
            $error,
            $orderId,
            $providerMessageId,
        ]);
    } catch (\Throwable $e) {
        error_log('maxLogOutboundThread insert skipped: ' . $e->getMessage());
    }
}

/**
 * Записать результат отправки в журнал max_notifications.
 * Глотает дубль-вставки по UNIQUE(order_id) — это не ошибка (гонка повторных событий).
 */
function maxLogNotification(
    Database $dbh, int $orderId, ?int $userId, ?string $phone, ?string $title, ?string $message,
    string $status, ?int $httpCode, ?string $response, ?string $error
): void {
    try {
        $dbh->insert('max_notifications', [
            'order_id'          => $orderId,
            'user_id'           => $userId,
            'phone'             => $phone,
            'product_title'     => $title !== null ? mb_substr($title, 0, 255) : null,
            'message'           => $message,
            'status'            => $status,
            'http_code'         => $httpCode,
            'provider_response' => $response !== null ? mb_substr($response, 0, 2000) : null,
            'error'             => $error,
        ]);
    } catch (\Throwable $e) {
        // Дубль по UNIQUE(order_id) при гонке повторных webhook-событий — норма, игнорируем.
        error_log('maxLogNotification insert skipped: ' . $e->getMessage());
    }
}

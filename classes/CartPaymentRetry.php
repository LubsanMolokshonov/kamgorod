<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Order.php';

final class CartPaymentRetry
{
    // Тип позиции в расчёте корзины → поле order_items и тип в cart_items.
    private const TYPES = [
        'registration' => ['registration_id', 'registration'],
        'certificate' => ['certificate_id', 'publication_cert'],
        'webinar_certificate' => ['webinar_certificate_id', 'webinar_cert'],
        'olympiad_registration' => ['olympiad_registration_id', 'olympiad_reg'],
    ];

    public static function reservationKeys(array $items): array
    {
        $keys = [];
        foreach ($items as $item) {
            $keys[] = ['type' => self::TYPES[$item['type']][1], 'id' => (int)$item['id']];
        }
        return $keys;
    }

    /**
     * Вызывается в одной транзакции с созданием и резервированием нового заказа.
     * Возвращает переход к существующему платежу либо null, если можно оформить новый.
     */
    public static function resolve(PDO $db, int $userId, int $sessionUserId, array $items, float $total, callable $lookup, ?int $resumeId = null): ?array
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Проверка оплаты требует транзакции');
        }
        if ($userId <= 0 || ($sessionUserId && $sessionUserId !== $userId)) {
            throw new DomainException('Откройте корзину в аккаунте владельца документов.');
        }
        $lock = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $requested = [];
        foreach ($items as $item) {
            $type = $item['type'];
            $id = (int)$item['id'];
            if (!isset(self::TYPES[$type]) || $id <= 0 || isset($requested[$type][$id])) {
                throw new DomainException('Корзина изменилась. Обновите страницу.');
            }
            $requested[$type][$id] = [self::minor($item['price']), (bool)$item['is_free']];
        }
        $candidates = [];
        // Блокируем сами документы: строка cart_items может ещё отсутствовать
        // у гостя или уже быть удалена параллельной выдачей оплаченного документа.
        foreach (self::TYPES as $type => [$column, $cartType]) {
            if (empty($requested[$type])) continue;
            $ids = array_keys($requested[$type]);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $config = CART_PAID_STATUSES[$cartType];
            $stmt = $db->prepare("SELECT id, user_id, status FROM {$config['table']} WHERE id IN ($in) ORDER BY id" . $lock);
            $stmt->execute($ids);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) !== count($ids)) throw new DomainException('Корзина изменилась. Обновите страницу.');
            foreach ($rows as $row) {
                if ((int)$row['user_id'] !== $userId || (!$sessionUserId && !guestOwnsCartItem($cartType, (int)$row['id'], $userId))) throw new DomainException('Откройте корзину в аккаунте владельца документов.');
                if (in_array($row['status'], $config['statuses'], true)) throw new DomainException('Документ уже оплачен. Обновите корзину.');
            }
        }
        foreach (self::TYPES as $type => [$column, $cartType]) {
            if (empty($requested[$type])) continue;
            $ids = array_keys($requested[$type]);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $db->prepare(
                "SELECT oi.order_id FROM order_items oi JOIN orders o ON o.id = oi.order_id
                 WHERE oi.$column IN ($in) AND o.payment_status IN ('pending','processing','succeeded')
                 UNION SELECT reserved_in_order_id FROM cart_items WHERE user_id = ? AND item_type = ?
                 AND item_id IN ($in) AND reserved_in_order_id IS NOT NULL"
            );
            $stmt->execute([...$ids, $userId, $cartType, ...$ids]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) $candidates[(int)$id] = true;
        }

        $redirect = null;
        ksort($candidates);
        foreach (array_keys($candidates) as $id) {
            if ($resumeId !== null && $resumeId !== $id) {
                throw new DomainException('Документ входит в другой заказ. Обновите корзину для проверки.');
            }
            if ($sessionUserId !== $userId && !guestOwnsOrder($id, $userId)) throw new DomainException('Войдите в аккаунт владельца, чтобы продолжить оплату.');
            $stmt = $db->prepare('SELECT orders.*, CURRENT_TIMESTAMP AS checkout_now FROM orders WHERE id = ? AND user_id = ?' . $lock);
            $stmt->execute([$id, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new DomainException('Не удалось сверить заказ. Обратитесь в поддержку.');
            $status = $order['payment_status'];
            if (in_array($status, ['succeeded', 'refunded'], true)) throw new DomainException('Заказ уже обработан. Проверьте документы в личном кабинете.');
            $paymentId = $order['yookassa_payment_id'] ?? '';
            if ($paymentId === '' && $status === 'pending' && self::minor($order['final_amount']) === 0) {
                if ($redirect || !$sessionUserId || !self::sameItems($db, $id, $requested, true)) {
                    throw new DomainException('В корзине есть документы незавершённого заказа по подписке. Продолжите его оформление отдельно.');
                }
                $redirect = ['covered_order_id' => $id, 'order_number' => $order['order_number']];
                continue;
            }
            if ($paymentId === '' && $status === 'pending') {
                // Заказ сохранён перед запросом createPayment. При потере ответа
                // повторим тот же заказ с прежним ключом в пределах окна ЮKassa.
                $age = strtotime($order['checkout_now'] . ' UTC') - strtotime($order['created_at'] . ' UTC');
                if ($redirect || $age < 0 || $age >= 23 * 3600 || $total <= 0
                    || self::minor($order['final_amount']) !== self::minor($total) || !self::sameItems($db, $id, $requested)
                    || !self::hasAllReservations($db, $id, $userId, $items)) {
                    throw new DomainException('Статус предыдущей попытки требует сверки. Обратитесь в поддержку.');
                }
                $attempt = $db->prepare('SELECT payload_hash FROM cart_payment_attempts WHERE order_id = ?');
                $attempt->execute([$id]);
                if (!$attempt->fetchColumn()) {
                    throw new DomainException('Статус предыдущей попытки требует сверки. Обратитесь в поддержку.');
                }
                $redirect = ['retry_order_id' => $id, 'order_number' => $order['order_number']];
                continue;
            }
            if (!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/iD', $paymentId)) {
                throw new DomainException('Статус предыдущей оплаты уточняется. Попробуйте позже.');
            }
            try {
                $payment = $lookup($paymentId);
                $metadata = $payment->getMetadata();
                $verified = $payment->getId() === $paymentId && (int)($metadata['order_id'] ?? 0) === $id
                    && (int)($metadata['user_id'] ?? 0) === $userId && $payment->getAmount()->getCurrency() === 'RUB'
                    && self::minor($payment->getAmount()->getValue()) === self::minor($order['final_amount']);
                $gatewayStatus = $payment->getStatus();
            } catch (Throwable $e) {
                error_log('Не удалось проверить платёж корзины, заказ ' . $id . ' (' . get_class($e) . ')');
                throw new DomainException('Не удалось уточнить статус оплаты. Попробуйте через несколько минут.');
            }
            if (!$verified) throw new DomainException('Не удалось сверить платёж с заказом. Обратитесь в поддержку.');
            if ($gatewayStatus === 'canceled' && (new Order($db))->cancelYookassaPayment($id, $paymentId)) continue;
            if ($resumeId === $id && in_array($gatewayStatus, ['succeeded', 'waiting_for_capture'], true)) {
                return self::statusPage($order);
            }
            if ($gatewayStatus !== 'pending' || $status !== 'pending') {
                throw new DomainException('Предыдущий заказ обрабатывается. Проверьте его статус в личном кабинете.');
            }
            if ($redirect || self::minor($total) !== self::minor($order['final_amount']) || !self::sameItems($db, $id, $requested)) {
                throw new DomainException('Состав или стоимость корзины отличаются от открытого заказа. Для продолжения верните исходные позиции или дождитесь окончания первой оплаты.');
            }
            $confirmation = $payment->getConfirmation();
            $url = $confirmation && method_exists($confirmation, 'getConfirmationUrl')
                ? $confirmation->getConfirmationUrl() : null;
            $url = $url ?: ($order['yookassa_confirmation_url'] ?? '');
            $parts = parse_url($url);
            if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['port']) || !in_array(strtolower($parts['host'] ?? ''), ['yoomoney.ru', 'yookassa.ru'], true)
                || preg_match('/[\x00-\x20\\\\]/', $url)) {
                throw new DomainException('Не удалось открыть ссылку на оплату. Обратитесь в поддержку.');
            }
            $redirect = ['success' => true, 'redirect_url' => $url, 'order_number' => $order['order_number'], 'payment_id' => $paymentId];
        }
        return $redirect;
    }

    public static function statusPage(array $order): array
    {
        return ['success' => true, 'order_number' => $order['order_number'],
            'redirect_url' => '/pages/payment-success.php?order_number=' . rawurlencode($order['order_number'])];
    }

    /** Сохранённый заказ доступен и на другом устройстве, когда корзина ещё не хранилась в БД. */
    public static function openOrders(PDO $db, ?int $accountId): array
    {
        $ownerFilter = 'o.user_id = ?';
        $params = [$accountId];
        if ($accountId === null) {
            $grants = array_keys($_SESSION['guest_resources']['order'] ?? []);
            if (!$grants) return [];
            $ownerFilter = 'o.id IN (' . implode(',', array_fill(0, count($grants), '?')) . ')';
            $params = $grants;
        }
        $conditions = [];
        foreach (self::TYPES as [$column, $cartType]) {
            $cfg = CART_PAID_STATUSES[$cartType];
            $statuses = implode(',', array_fill(0, count($cfg['statuses']), '?'));
            $conditions[] = "EXISTS (SELECT 1 FROM {$cfg['table']} d WHERE d.id = oi.$column
                AND d.user_id = o.user_id AND d.status NOT IN ($statuses))";
            array_push($params, ...$cfg['statuses']);
        }
        $stmt = $db->prepare("SELECT o.id, o.order_number, o.user_id, o.final_amount, o.payment_status
              FROM orders o WHERE $ownerFilter AND o.payment_status IN ('pending','processing') AND o.subscription_plan_id IS NULL
               AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND (" . implode(' OR ', $conditions) . "))
               AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL)
              ORDER BY o.id DESC");
        $stmt->execute($params);
        $orders = [];
        $model = new Order($db);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $owner = (int)$row['user_id'];
            $id = (int)$row['id'];
            if ($accountId !== null ? $accountId !== $owner : !guestOwnsOrder($id, $owner)) continue;
            $row['items'] = $model->getOrderItems($id);
            $orders[] = $row;
        }
        return $orders;
    }

    public static function unreservedItems(array $items, array $orders): array
    {
        $reserved = [];
        foreach ($orders as $order) {
            foreach ($order['items'] as $line) {
                foreach (self::TYPES as $type => [$column]) {
                    if (!empty($line[$column])) $reserved[$type][(int)$line[$column]] = true;
                }
            }
        }
        return array_values(array_filter($items, static fn($item) => !isset($reserved[$item['type']][(int)$item['id']])));
    }

    /** Не перенаправлять старую вкладку на заказ с иными позициями, даже при той же сумме. */
    public static function matchesShownQuote(array $order, array $quote): bool
    {
        if (self::minor($order['final_amount']) !== self::minor($quote['total'])
            || count($quote['items']) !== count($order['items'])) return false;
        $lines = [];
        foreach ($quote['items'] as $item) {
            $lines[$item['type']][(int)$item['id']] = $quote['subscriber'] ? [0, true]
                : [self::minor($item['price']), (bool)$item['is_free']];
        }
        foreach ($order['items'] as $row) {
            $matched = false;
            foreach (self::TYPES as $type => [$column]) {
                $id = (int)($row[$column] ?? 0);
                if (!$id) continue;
                if ($matched || ($lines[$type][$id] ?? null) !== [self::minor($row['price']), (bool)$row['is_free_promotion']]) return false;
                $matched = true;
                unset($lines[$type][$id]);
            }
            if (!$matched) return false;
        }
        return !array_filter($lines);
    }

    /** Продолжить именно заказ, показанный пользователю, включая его исходную сумму и строки. */
    public static function resume(PDO $db, int $id, ?int $accountId, object $client): array
    {
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ?');
        $stmt->execute([$id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $owner = (int)($order['user_id'] ?? 0);
        if (!$order || ($accountId !== null ? $accountId !== $owner : !guestOwnsOrder($id, $owner))
            || !empty($order['subscription_plan_id'])) {
            throw new DomainException('Заказ недоступен. Откройте корзину в браузере оформления или войдите по почте.');
        }
        $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$id]);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $items = [];
        foreach ($lines as $line) {
            if (!empty($line['course_enrollment_id'])) throw new DomainException('Откройте оплату курса в кабинете.');
            foreach (self::TYPES as $type => [$column]) {
                if (!empty($line[$column])) {
                    $items[] = ['type' => $type, 'id' => (int)$line[$column], 'price' => $line['price'],
                        'is_free' => (bool)$line['is_free_promotion']];
                }
            }
        }
        if (!$items || count($items) !== count($lines)) throw new DomainException('Не удалось сверить состав заказа.');
        if (!in_array($order['payment_status'], ['pending','processing'], true)) return self::statusPage($order);
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_ACTIVE) session_write_close();
        $paymentId = $order['yookassa_payment_id'] ?? '';
        $payment = $paymentId !== '' ? $client->getPaymentInfo($paymentId) : null;
        $db->beginTransaction();
        try {
            $result = self::resolve($db, $owner, (int)$accountId, $items, (float)$order['final_amount'],
                static function (string $pid) use ($payment, $paymentId) {
                    if (!$payment || $pid !== $paymentId) throw new RuntimeException('Сведения о платеже изменились');
                    return $payment;
                }, $id);
            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            // Во время опроса банка вебхук мог уже выдать документы или оформить возврат.
            if ($e instanceof DomainException) {
                $current = $db->prepare('SELECT payment_status FROM orders WHERE id = ? AND user_id = ?');
                $current->execute([$id, $owner]);
                if (in_array($current->fetchColumn(), ['succeeded', 'refunded', 'failed'], true)) {
                    return self::statusPage($order);
                }
            }
            throw $e;
        }
        if (!$result || isset($result['redirect_url'])) return $result ?? self::statusPage($order);
        if (isset($result['covered_order_id'])) {
            require_once __DIR__ . '/../includes/order-fulfillment.php';
            fulfillOrderItems($db, $id, 'subscription');
            return self::statusPage($order);
        }
        $payload = self::savedPayload($db, $id);
        if (!$payload) throw new DomainException('Для старого заказа не сохранился исходный чек. Статус оплаты требует сверки.');
        return self::dispatch($db, $client, $order, $payload, true);
    }

    /** После фиксации заказа вызываем провайдера с одним ключом, без сетевого I/O под блокировкой заказа. */
    public static function dispatch(PDO $db, object $client, array $order, array $payload, bool $retry): array
    {
        $orderId = (int)$order['id'];
        $userId = (int)$order['user_id'];
        $orderNumber = $order['order_number'];
        $read = $db->prepare('SELECT payment_status, yookassa_payment_id FROM orders WHERE id = ? AND user_id = ?');
        $read->execute([$orderId, $userId]);
        $saved = $read->fetch(PDO::FETCH_ASSOC);
        if (!$saved) throw new DomainException('Заказ недоступен.');
        if (!in_array($saved['payment_status'], ['pending','processing'], true)) return self::statusPage($order);
        if (!empty($saved['yookassa_payment_id'])) {
            $payment = $client->getPaymentInfo($saved['yookassa_payment_id']);
        } else {
            try {
                $payment = $client->createPayment($payload, $orderNumber);
            } catch (\YooKassa\Common\Exceptions\BadApiRequestException $e) {
                $details = json_decode((string)$e->getResponseBody(), true);
                if (!$retry && ($details['code'] ?? '') === 'invalid_request'
                    && preg_match('/^(amount|receipt|confirmation|description|metadata)(?:$|[.\[])/D', $details['parameter'] ?? '')) {
                    $db->beginTransaction();
                    try {
                        $failed = $db->prepare("UPDATE orders SET payment_status = 'failed' WHERE id = ? AND payment_status = 'pending'
                            AND (yookassa_payment_id IS NULL OR yookassa_payment_id = '')");
                        $failed->execute([$orderId]);
                        if ($failed->rowCount()) {
                            $db->prepare('UPDATE cart_items SET reserved_in_order_id = NULL WHERE reserved_in_order_id = ?')->execute([$orderId]);
                        }
                        $db->commit();
                    } catch (Throwable $writeError) {
                        if ($db->inTransaction()) $db->rollBack();
                        throw $writeError;
                    }
                    throw new DomainException('ЮKassa отклонила данные заказа. Проверьте корзину.');
                }
                throw $e;
            }
        }
        $paymentId = $payment->getId();
        $confirmation = $payment->getConfirmation()?->getConfirmationUrl();
        $update = $db->prepare('UPDATE orders SET yookassa_payment_id = ?, yookassa_confirmation_url = COALESCE(?, yookassa_confirmation_url)
            WHERE id = ? AND user_id = ? AND (yookassa_payment_id IS NULL OR yookassa_payment_id = ?)');
        $update->execute([$paymentId, $confirmation, $orderId, $userId, $paymentId]);
        $read->execute([$orderId, $userId]);
        $current = $read->fetch(PDO::FETCH_ASSOC);
        if (!$current || $current['yookassa_payment_id'] !== $paymentId) throw new DomainException('Не удалось связать платёж с заказом.');
        if ($payment->getStatus() === 'canceled') (new Order($db))->cancelYookassaPayment($orderId, $paymentId);
        if ($payment->getStatus() !== 'pending' || $current['payment_status'] !== 'pending') return self::statusPage($order);
        if (!$confirmation) throw new DomainException('ЮKassa уточняет статус оплаты. Вернитесь в корзину через несколько минут.');
        return ['success' => true, 'order_number' => $orderNumber, 'payment_id' => $paymentId, 'redirect_url' => $confirmation];
    }

    /** Снимок включает сумму, строки чека и метаданные на момент оформления. */
    public static function savedPayload(PDO $db, int $orderId): ?array
    {
        $stmt = $db->prepare('SELECT payload_hash, payload_json FROM cart_payment_attempts WHERE order_id = ?');
        $stmt->execute([$orderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['payload_json'] === null) return null;
        if (!hash_equals($row['payload_hash'], hash('sha256', $row['payload_json']))) {
            throw new DomainException('Не удалось проверить сохранённый чек. Обратитесь в поддержку.');
        }
        return json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function rememberPayload(PDO $db, int $orderId, array $payload, bool $retry): void
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Фиксация попытки оплаты требует транзакции');
        }
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $hash = hash('sha256', $json);
        if ($retry) {
            $stmt = $db->prepare('SELECT payload_hash FROM cart_payment_attempts WHERE order_id = ?');
            $stmt->execute([$orderId]);
            $original = $stmt->fetchColumn();
            if (!is_string($original) || !hash_equals($original, $hash)) {
                throw new DomainException('Данные предыдущего платежа изменились. Обратитесь в поддержку для проверки заказа.');
            }
            return;
        }
        $stmt = $db->prepare('INSERT INTO cart_payment_attempts (order_id, payload_hash, payload_json) VALUES (?, ?, ?)');
        $stmt->execute([$orderId, $hash, $json]);
    }

    private static function hasAllReservations(PDO $db, int $orderId, int $userId, array $items): bool
    {
        $stmt = $db->prepare('SELECT item_type, item_id FROM cart_items WHERE reserved_in_order_id = ? AND user_id = ?');
        $stmt->execute([$orderId, $userId]);
        $keys = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) $keys[$row['item_type'] . ':' . $row['item_id']] = true;
        foreach (self::reservationKeys($items) as $item) {
            $key = $item['type'] . ':' . $item['id'];
            if (!isset($keys[$key])) return false;
            unset($keys[$key]);
        }
        return !$keys;
    }

    private static function sameItems(PDO $db, int $orderId, array $remaining, bool $covered = false): bool
    {
        $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['course_enrollment_id']) || (bool)$row['covered_by_subscription'] !== $covered) return false;
            $matches = 0;
            foreach (self::TYPES as $type => [$column]) {
                if (empty($row[$column])) continue;
                $id = (int)$row[$column];
                if (!isset($remaining[$type][$id])) return false;
                if ($covered ? self::minor($row['price']) !== 0
                    : $remaining[$type][$id] !== [self::minor($row['price']), (bool)$row['is_free_promotion']]) return false;
                unset($remaining[$type][$id]);
                $matches++;
            }
            if ($matches !== 1) return false;
        }
        return !array_filter($remaining);
    }

    private static function minor($amount): int
    {
        return (int)round((float)$amount * 100);
    }
}

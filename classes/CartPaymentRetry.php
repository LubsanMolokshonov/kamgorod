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
    public static function resolve(PDO $db, int $userId, int $sessionUserId, array $items, float $total, callable $lookup): ?array
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
                if ((int)$row['user_id'] !== $userId) throw new DomainException('Откройте корзину в аккаунте владельца документов.');
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
            if ($sessionUserId !== $userId) throw new DomainException('Войдите в аккаунт владельца, чтобы продолжить оплату.');
            $stmt = $db->prepare('SELECT orders.*, CURRENT_TIMESTAMP AS checkout_now FROM orders WHERE id = ? AND user_id = ?' . $lock);
            $stmt->execute([$id, $userId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$order) throw new DomainException('Не удалось сверить заказ. Обратитесь в поддержку.');
            $status = $order['payment_status'];
            if (in_array($status, ['succeeded', 'refunded'], true)) throw new DomainException('Заказ уже обработан. Проверьте документы в личном кабинете.');
            $paymentId = $order['yookassa_payment_id'] ?? '';
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

    /** Сохраняем только отпечаток запроса в транзакции создания заказа и резерва. */
    public static function rememberPayload(PDO $db, int $orderId, array $payload, bool $retry): void
    {
        if (!$db->inTransaction()) {
            throw new LogicException('Фиксация попытки оплаты требует транзакции');
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        if ($retry) {
            $stmt = $db->prepare('SELECT payload_hash FROM cart_payment_attempts WHERE order_id = ?');
            $stmt->execute([$orderId]);
            $original = $stmt->fetchColumn();
            if (!is_string($original) || !hash_equals($original, $hash)) {
                throw new DomainException('Данные предыдущего платежа изменились. Обратитесь в поддержку для проверки заказа.');
            }
            return;
        }
        $stmt = $db->prepare('INSERT INTO cart_payment_attempts (order_id, payload_hash) VALUES (?, ?)');
        $stmt->execute([$orderId, $hash]);
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

    private static function sameItems(PDO $db, int $orderId, array $remaining): bool
    {
        $stmt = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $stmt->execute([$orderId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!empty($row['course_enrollment_id']) || !empty($row['covered_by_subscription'])) return false;
            $matches = 0;
            foreach (self::TYPES as $type => [$column]) {
                if (empty($row[$column])) continue;
                $id = (int)$row[$column];
                if (($remaining[$type][$id] ?? null) !== [self::minor($row['price']), (bool)$row['is_free_promotion']]) return false;
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

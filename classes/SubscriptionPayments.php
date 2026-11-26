<?php
declare(strict_types=1);

require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/Order.php';

/** Ручная оплата и рекуррент: создаём заказ, тело запроса и ключ до первого обращения к магазину. */
final class SubscriptionPayments
{
    private Database $db;

    public function __construct(private PDO $pdo, private object $client, private ?Closure $afterActivation = null)
    {
        $this->db = new Database($pdo);
    }

    public function manual(int $userId, array $plan, string $period, bool $save, ?string $requestKey): array
    {
        return self::withUserLock($this->pdo, $userId, function () use ($userId, $plan, $period, $save, $requestKey) {
            $attempt = null;
            if ($requestKey !== null) {
                $mapped = $this->db->queryOne('SELECT order_id FROM subscription_payment_requests WHERE user_id=? AND request_key=?', [$userId, $requestKey]);
                if ($mapped) {
                    $attempt = $this->attempt((int)$mapped['order_id']);
                    if ((int)$attempt['subscription_plan_id'] !== (int)$plan['id'] || $attempt['subscription_period'] !== $period) {
                        throw new DomainException('Обновите страницу и снова выберите тариф.');
                    }
                    if ($attempt['payment_status'] === 'failed') {
                        $this->db->execute('DELETE FROM subscription_payment_requests WHERE user_id=? AND request_key=?', [$userId, $requestKey]);
                        $attempt = null;
                    }
                }
            }
            if (!$attempt) {
                if ($this->db->queryOne("SELECT 1 FROM subscription_payment_attempts a JOIN orders o ON o.id=a.order_id
                     WHERE o.user_id=? AND a.scope_key LIKE 'renew:%' LIMIT 1", [$userId])) {
                    throw new DomainException('Проверяем начатое автопродление. Дождитесь результата.');
                }
                $scope = "manual:{$userId}:{$plan['id']}:{$period}";
                $attempt = $this->forScope($scope);
                if (!$attempt) {
                    // У старого заказа нет сохранённого тела запроса. Известный платёж
                    // можно продолжить, неизвестный сначала проходит сверку.
                    $legacy = $this->db->query(
                        "SELECT o.id FROM orders o JOIN subscription_cutover c ON c.id=1 AND o.id<=c.max_order_id
                         WHERE o.user_id=? AND o.subscription_plan_id=? AND o.subscription_period=?
                           AND o.payment_status IN ('pending','processing') ORDER BY o.id DESC LIMIT 2",
                        [$userId, $plan['id'], $period]
                    );
                    if (count($legacy) > 1) {
                        throw new DomainException('Предыдущие платежи проверяются. Обратитесь в поддержку.');
                    }
                    $attempt = $legacy ? $this->attempt((int)$legacy[0]['id']) : null;
                }
                if (!$attempt) {
                    $user = $this->db->queryOne('SELECT email FROM users WHERE id=?', [$userId]);
                    if (!$user) { throw new DomainException('Войдите в личный кабинет.'); }
                    $attempt = $this->prepare($scope, $userId, $plan, $period, (string)$user['email'], $save, null);
                }
                if ($requestKey !== null) {
                    $this->db->insert('subscription_payment_requests', ['user_id' => $userId, 'request_key' => $requestKey, 'order_id' => $attempt['id']]);
                }
            }
            if ($attempt['payment_status'] === 'succeeded') {
                return ['status' => 'succeeded', 'confirmation_url' => '/kabinet/?subscription=success'];
            }
            if ($attempt['payment_status'] === 'refunded') {
                throw new DomainException('Оплата была возвращена. Для новой покупки обновите страницу.');
            }
            if ($attempt['payload']) {
                $payload = json_decode($attempt['payload'], true, 512, JSON_THROW_ON_ERROR);
                if (($payload['save_payment_method'] ?? false) && !$save) {
                    throw new DomainException('Предыдущая попытка оформлена с автопродлением. Для разовой оплаты дождитесь её отмены или обратитесь в поддержку.');
                }
            }
            $result = $this->sendOrRead($attempt);
            if ($result['status'] === 'canceled') {
                throw new DomainException('Платёж отменён. Нажмите «Оплатить» для новой попытки.');
            }
            if (!$result['confirmation_url'] && $result['status'] !== 'succeeded') {
                throw new DomainException('Платёж проверяется. Повторите проверку позже.');
            }
            return $result;
        });
    }

    public function renew(array $candidate, int $maxAttempts, int $leadDays): array
    {
        $userId = (int)$candidate['user_id'];
        return self::withUserLock($this->pdo, $userId, function () use ($candidate, $maxAttempts, $leadDays, $userId) {
            $clock = new DateTimeImmutable((string)$this->pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn(), new DateTimeZone('UTC'));
            $deadline = $clock->modify('+' . max(0, $leadDays) . ' days')->format('Y-m-d H:i:s');
            $sub = $this->db->queryOne(
                "SELECT s.*, p.name, p.slug, p.price_monthly, p.price_yearly, u.email, u.pricing_variant
                 FROM user_subscriptions s JOIN subscription_plans p ON p.id=s.plan_id JOIN users u ON u.id=s.user_id
                 WHERE s.id=? AND s.status='active' AND s.auto_renew=1 AND s.yookassa_payment_method_id IS NOT NULL
                   AND s.expires_at<=?",
                [$candidate['id'], $deadline]
            );
            if (!$sub || !SUBSCRIPTION_AUTORENEW_ENABLED || !$sub['yookassa_payment_method_id']) {
                return ['status' => 'skipped'];
            }
            $scope = 'renew:' . $sub['id'] . ':' . $sub['expires_at'];
            $attempt = $this->forScope($scope);
            if (!$attempt) {
                if ($this->db->queryOne("SELECT 1 FROM subscription_payment_attempts a JOIN orders o ON o.id=a.order_id
                     WHERE o.user_id=? AND a.scope_key LIKE 'manual:%' LIMIT 1", [$userId])) {
                    return ['status' => 'skipped'];
                }
                if ((int)$sub['renewal_attempt_count'] >= $maxAttempts || ($sub['last_renewal_attempt_at']
                    && $sub['last_renewal_attempt_at'] > $clock->modify('-12 hours')->format('Y-m-d H:i:s'))) {
                    return ['status' => 'skipped'];
                }
                // Не отправляем новое списание, пока старая попытка за этот цикл не получила подтверждённый исход.
                $legacyCutoff = $sub['last_renewal_attempt_at'] ? (new DateTimeImmutable($sub['last_renewal_attempt_at'], new DateTimeZone('UTC')))->modify('-5 minutes')->format('Y-m-d H:i:s') : null;
                $legacy = $this->db->queryOne(
                    "SELECT o.id FROM orders o JOIN subscription_cutover c ON c.id=1 AND o.id<=c.max_order_id
                     WHERE o.user_id=? AND o.subscription_plan_id=? AND o.subscription_period=?
                       AND (o.payment_status IN ('pending','processing') OR (o.payment_status='failed' AND COALESCE(o.yookassa_payment_id,'')=''))
                       AND o.created_at>=?
                     ORDER BY o.id DESC LIMIT 1",
                    [$userId, $sub['plan_id'], $sub['period'], $legacyCutoff]
                );
                if ($legacy && (int)$sub['renewal_attempt_count'] > 0) {
                    return ['status' => 'review'];
                }
                $plan = ['id' => $sub['plan_id'], 'name' => $sub['name'], 'slug' => $sub['slug'],
                    'price_monthly' => $sub['price_monthly'], 'price_yearly' => $sub['price_yearly']];
                $attempt = $this->prepare($scope, $userId, $plan, $sub['period'], $sub['email'], false, $sub);
            }
            return $this->sendOrRead($attempt);
        });
    }

    public static function withUserLock(PDO $pdo, int $userId, callable $work): mixed
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
            return $work();
        }
        $key = 'fgos_subscription_payment:' . $userId;
        $stmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $stmt->execute([$key]);
        $locked = $stmt->fetchColumn();
        $stmt->closeCursor();
        if ((int)$locked !== 1) { throw new RuntimeException('Оформление подписки занято'); }
        try {
            return $work();
        } finally {
            $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$key]);
        }
    }

    private function attempt(int $orderId): array
    {
        $order = $this->db->queryOne(
            'SELECT o.*, a.scope_key, a.attempt_key, a.payload, a.started_at, a.subscription_id
             FROM orders o LEFT JOIN subscription_payment_attempts a ON a.order_id=o.id WHERE o.id=? AND o.subscription_plan_id IS NOT NULL', [$orderId]
        );
        if (!$order) { throw new DomainException('Заказ подписки не найден'); }
        return $order;
    }

    private function forScope(string $scope): ?array
    {
        $row = $this->db->queryOne('SELECT order_id FROM subscription_payment_attempts WHERE scope_key=?', [$scope]);
        return $row ? $this->attempt((int)$row['order_id']) : null;
    }

    private function prepare(string $scope, int $userId, array $plan, string $period, string $email, bool $save, ?array $sub): array
    {
        $value = number_format((float)$plan[$period === 'yearly' ? 'price_yearly' : 'price_monthly'], 2, '.', '');
        if ((float)$value <= 0 || $email === '') { throw new DomainException('Не удалось определить цену или адрес для чека'); }
        $amount = ['value' => $value, 'currency' => 'RUB'];
        $label = $period === 'yearly' ? 'год' : 'месяц';
        $title = ($sub ? 'Продление подписки' : 'Подписка') . " «{$plan['name']}» на fgos.pro ({$label})";
        $key = bin2hex(random_bytes(16));
        $this->pdo->beginTransaction();
        try {
            $orderId = (int)$this->db->insert('orders', [
                'user_id' => $userId, 'order_number' => Order::generateOrderNumber(), 'total_amount' => $value,
                'final_amount' => $value, 'discount_amount' => 0, 'payment_status' => 'pending',
                'subscription_plan_id' => $plan['id'], 'subscription_period' => $period,
            ]);
            if ($sub && in_array($sub['pricing_variant'], ['A','B'], true)) {
                $this->db->update('orders', ['pricing_variant' => $sub['pricing_variant']], 'id=?', [$orderId]);
            } elseif (!$sub) {
                require_once __DIR__ . '/PricingMode.php';
                PricingMode::stampOrder($this->pdo, $orderId);
            }
            $payload = ['amount' => $amount, 'capture' => true, 'description' => $title,
                'receipt' => ['customer' => ['email' => $email], 'items' => [[
                    'description' => $title, 'quantity' => 1, 'amount' => $amount, 'vat_code' => 1,
                    'payment_mode' => 'full_payment', 'payment_subject' => 'service',
                ]]], 'metadata' => ['payment_type' => 'subscription', 'user_id' => $userId, 'order_id' => $orderId,
                    'plan_id' => $plan['id'], 'plan_slug' => $plan['slug'], 'period' => $period, 'subscription_attempt' => $key]];
            if ($sub) {
                $payload['metadata']['renewal'] = '1';
                $payload['payment_method_id'] = $sub['yookassa_payment_method_id'];
                $this->db->execute('UPDATE user_subscriptions SET renewal_attempt_count=renewal_attempt_count+1, last_renewal_attempt_at=CURRENT_TIMESTAMP WHERE id=?', [$sub['id']]);
            } else {
                $payload['metadata']['auto_renew'] = $save ? '1' : '0';
                $payload['save_payment_method'] = $save;
                $payload['confirmation'] = ['type' => 'redirect', 'return_url' => SITE_URL . '/pages/cabinet.php?subscription=success'];
            }
            $this->db->insert('subscription_payment_attempts', [
                'order_id' => $orderId, 'scope_key' => $scope, 'attempt_key' => $key,
                'subscription_id' => $sub['id'] ?? null, 'cycle_ends_at' => $sub['expires_at'] ?? null,
                'started_at' => gmdate('Y-m-d H:i:s'), 'next_check_at' => gmdate('Y-m-d H:i:s', time()+600),
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
            $this->pdo->commit();
        } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        return $this->attempt($orderId);
    }

    private function sendOrRead(array $attempt): array
    {
        if (!empty($attempt['yookassa_payment_id'])) {
            $payment = $this->client->getPaymentInfo($attempt['yookassa_payment_id']);
        } else {
            if (!$attempt['attempt_key'] || !$attempt['payload'] ||
                strtotime($attempt['started_at'] . ' UTC') + 23 * 3600 <= time()) {
                throw new DomainException('Предыдущий платёж проверяется. Обратитесь в поддержку с номером ' . $attempt['order_number']);
            }
            $payload = json_decode($attempt['payload'], true, 512, JSON_THROW_ON_ERROR);
            try {
                $payment = $this->client->createPayment($payload, $attempt['attempt_key']);
            } catch (\YooKassa\Common\Exceptions\ForbiddenException $e) {
                if (empty($payload['save_payment_method'])) { throw $e; }
                // От магазина получен определённый отказ. Сохраняем новую разовую попытку прежде чем её отправить.
                $payload['save_payment_method'] = false;
                $payload['metadata']['auto_renew'] = '0';
                $key = $payload['metadata']['subscription_attempt'] = bin2hex(random_bytes(16));
                $this->db->update('subscription_payment_attempts', ['attempt_key' => $key, 'started_at' => gmdate('Y-m-d H:i:s'),
                    'payload' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                    'order_id=? AND attempt_key=?', [$attempt['id'], $attempt['attempt_key']]);
                $payment = $this->client->createPayment($payload, $key);
            }
        }
        $result = $this->accept($payment, (int)$attempt['id']);
        $confirmation = $payment->getConfirmation();
        $url = $confirmation && method_exists($confirmation, 'getConfirmationUrl') ? $confirmation->getConfirmationUrl() : null;
        return $result + ['confirmation_url' => $result['status'] === 'succeeded' ? '/kabinet/?subscription=success' : $url,
            'payment_id' => $payment->getId()];
    }

    /** Сверка не отправляет новых запросов на списание. Курсор сохраняется между прогонами. */
    public function reconcile(array &$stats): void
    {
        // Финансовая сверка уже проверяет накопленные суммы через API магазина.
        // Так восстановится пропущенное уведомление о полном возврате старого заказа.
        // checked_at установлен CURRENT_TIMESTAMP в часовом поясе БД, отметки очереди хранятся в UTC.
        $refundCheckedUtc = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? 'TIMESTAMPADD(SECOND,UNIX_TIMESTAMP(r.checked_at)-UNIX_TIMESTAMP(),UTC_TIMESTAMP())' : 'r.checked_at';
        $refunds = $this->db->query(
            "SELECT o.id, o.user_id FROM yookassa_refund_totals r JOIN orders o ON o.yookassa_payment_id=r.payment_id
             LEFT JOIN subscription_recheck_schedule retry ON retry.task='refund' AND retry.entity_id=o.id
             WHERE o.subscription_plan_id IS NOT NULL AND o.payment_status<>'refunded'
               AND r.currency='RUB' AND r.payment_amount=o.final_amount AND r.refunded_amount=r.payment_amount
               AND r.payment_amount>0 AND (retry.next_check_at IS NULL OR retry.next_check_at<=UTC_TIMESTAMP())
             ORDER BY COALESCE(retry.next_check_at,{$refundCheckedUtc}), o.id LIMIT 100"
        );
        foreach ($refunds as $refund) {
            $stats['checked']++;
            try {
                $this->db->execute("REPLACE INTO subscription_recheck_schedule (task,entity_id,next_check_at)
                    VALUES ('refund',?,?)", [$refund['id'], gmdate('Y-m-d H:i:s', time()+900)]);
                (new SubscriptionPeriods($this->pdo))->refund((int)$refund['id'], (int)$refund['user_id']);
                $this->db->execute("DELETE FROM subscription_recheck_schedule WHERE task='refund' AND entity_id=?", [$refund['id']]);
                $stats['recovered']++;
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log('Сверка возврата подписки, заказ #' . (int)$refund['id'] . ': ' . get_class($e));
            }
        }
        $rows = $this->db->query(
            "SELECT a.*, o.yookassa_payment_id FROM subscription_payment_attempts a JOIN orders o ON o.id=a.order_id
             WHERE a.next_check_at<=UTC_TIMESTAMP()
             ORDER BY a.next_check_at, a.order_id LIMIT 25"
        );
        foreach ($rows as $row) {
            $orderId = (int)$row['order_id'];
            $stats['checked']++;
            try {
                $next = gmdate('Y-m-d H:i:s', time() + 900);
                // Сдвиг до обращения к API: сбой одного заказа не задерживает остальные.
                $this->db->update('subscription_payment_attempts', ['next_check_at' => $next],
                    'order_id=? AND next_check_at IS NOT NULL', [$orderId]);
                if ($row['yookassa_payment_id']) {
                    $payment = $this->client->getPaymentInfo($row['yookassa_payment_id']);
                } else {
                    $start = strtotime($row['started_at'] . ' UTC');
                    $end = $row['scan_until'] === null ? min(time(), $start + 86400) : strtotime($row['scan_until'] . ' UTC');
                    $filter = ['created_at_gte' => gmdate('c', $start - 300), 'created_at_lte' => gmdate('c', $end), 'limit' => 100];
                    if ($row['scan_cursor']) {
                        $filter['cursor'] = $row['scan_cursor'];
                    }
                    $list = $this->client->getPayments($filter);
                    $payment = null;
                    foreach ($list->getItems() as $candidate) {
                        $meta = $candidate->getMetadata()?->toArray() ?? [];
                        if (($meta['payment_type'] ?? '') === 'subscription' && (int)($meta['order_id'] ?? 0) === $orderId) {
                            $payment = $candidate;
                            break;
                        }
                    }
                    $cursor = $list->getNextCursor();
                    if (!$payment) {
                        if ($cursor !== null && $cursor === $row['scan_cursor']) {
                            $this->db->update('subscription_payment_attempts', ['next_check_at' => null], 'order_id=?', [$orderId]);
                            throw new RuntimeException('Магазин повторил курсор проверки подписки');
                        }
                        $nextCheck = $cursor ? gmdate('Y-m-d H:i:s', time()+60)
                            : ($end < $start+86400 ? gmdate('Y-m-d H:i:s', time()+900) : null);
                        $this->db->update('subscription_payment_attempts',
                            ['scan_cursor' => $cursor, 'scan_until' => $cursor ? gmdate('Y-m-d H:i:s', $end) : null, 'next_check_at' => $nextCheck],
                            'order_id=? AND next_check_at IS NOT NULL', [$orderId]);
                        $stats['still_pending']++;
                        continue;
                    }
                }
                $result = $this->accept($payment, $orderId);
                if ($result['applied']) {
                    $stats['recovered']++;
                } elseif ($result['status'] === 'canceled') {
                    $stats['failed_marked']++;
                } else {
                    $stats['still_pending']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
                error_log('Сверка подписки, заказ #' . $orderId . ': ' . get_class($e));
            }
        }
    }

    /** Вызывается только с объектом, полученным от API магазина. */
    public function accept(object $payment, ?int $expectedOrderId = null): array
    {
        $meta = $payment->getMetadata()?->toArray() ?? [];
        $orderId = (int)($meta['order_id'] ?? 0);
        if (($meta['payment_type'] ?? '') !== 'subscription' || !$orderId || ($expectedOrderId !== null && $orderId !== $expectedOrderId)) {
            throw new DomainException('Платёж не соответствует заказу подписки');
        }
        $attempt = $this->attempt($orderId);
        $paymentId = (string)$payment->getId();
        $amount = $payment->getAmount();
        if ($paymentId === '' || !$amount || $amount->getCurrency() !== 'RUB'
            || (string)$amount->getValue() !== number_format((float)$attempt['final_amount'], 2, '.', '')
            || (int)($meta['user_id'] ?? 0) !== (int)$attempt['user_id']
            || (int)($meta['plan_id'] ?? 0) !== (int)$attempt['subscription_plan_id']
            || ($meta['period'] ?? null) !== $attempt['subscription_period']
            || (!empty($attempt['yookassa_payment_id']) && $paymentId !== $attempt['yookassa_payment_id'])
            || ($attempt['attempt_key'] !== null && $attempt['attempt_key'] !== ($meta['subscription_attempt'] ?? null))) {
            throw new DomainException('Реквизиты подтверждённого платежа не совпали с заказом');
        }
        $confirmation = $payment->getConfirmation();
        $url = $confirmation && method_exists($confirmation, 'getConfirmationUrl') ? $confirmation->getConfirmationUrl() : null;
        $this->db->execute("UPDATE orders SET yookassa_payment_id=?, yookassa_confirmation_url=COALESCE(?,yookassa_confirmation_url)
                         WHERE id=? AND (COALESCE(yookassa_payment_id,'')='' OR yookassa_payment_id=?)", [$paymentId, $url, $orderId, $paymentId]);
        $bound = $this->db->queryOne('SELECT yookassa_payment_id,payment_status FROM orders WHERE id=?', [$orderId]);
        if ($bound['yookassa_payment_id'] !== $paymentId) { throw new DomainException('В заказе зарегистрирован другой платёж'); }
        $status = (string)$payment->getStatus();
        $result = ['status' => $status, 'applied' => false, 'id' => 0];
        $refunded = $payment->getRefundedAmount();
        if ($bound['payment_status'] === 'refunded' || ($refunded && $refunded->getCurrency() === 'RUB' && (string)$refunded->getValue() === (string)$amount->getValue())) {
            (new SubscriptionPeriods($this->pdo))->refund($orderId, (int)$attempt['user_id']);
            return array_merge($result, ['status' => 'refunded']);
        }
        if ($status === 'succeeded') {
            $renewal = ($meta['renewal'] ?? '') === '1';
            $card = SubscriptionService::extractSavedPaymentMethod($payment);
            if (!$renewal && ($meta['auto_renew'] ?? '') === '0') {
                $card = ['id' => null, 'last4' => null, 'type' => null];
            }
            $grant = (new SubscriptionService($this->pdo))->applyPeriod((int)$attempt['user_id'], (int)$attempt['subscription_plan_id'],
                $attempt['subscription_period'], $orderId, $card, $renewal);
            $result = array_merge($result, $grant);
            if ($grant['applied']) {
                $userId = (int)$attempt['user_id'];
                if ($this->afterActivation) {
                    ($this->afterActivation)($userId, $grant['id'], $orderId);
                } else {
                    $this->notifyActivation($userId, $grant['id'], $orderId);
                }
            }
        } elseif ($status === 'canceled') {
            $this->pdo->beginTransaction();
            try {
                $this->db->execute("UPDATE orders SET payment_status='failed' WHERE id=? AND yookassa_payment_id=? AND payment_status IN ('pending','processing')", [$orderId, $paymentId]);
                $this->db->execute('UPDATE subscription_payment_attempts SET scope_key=NULL,payload=NULL,next_check_at=NULL WHERE order_id=?', [$orderId]);
                $this->pdo->commit();
            } catch (Throwable $e) { $this->pdo->rollBack(); throw $e; }
        }
        return $result;
    }
    private function notifyActivation(int $userId, int $subscriptionId, int $orderId): void
    {
        try {
            require_once __DIR__ . '/../includes/email-helper.php';
            sendSubscriptionActivatedEmail($userId, $subscriptionId);
        } catch (Throwable $e) {
            error_log('Ошибка письма об оплате подписки, заказ #' . $orderId);
        }
        try {
            require_once __DIR__ . '/LoyaltyDiscount.php';
            require_once __DIR__ . '/User.php';
            if (LoyaltyDiscount::isFirstSuccessfulOrder($this->pdo, $userId, $orderId)
                && (new User($this->pdo))->grantLifetimeDiscount($userId)) {
                scheduleDelayedEmail('lifetime_discount_granted', $userId, $orderId, 10);
            }
        } catch (Throwable $e) {
            error_log('Не удалось применить лояльность после оплаты подписки, заказ #' . $orderId);
        }
    }

}

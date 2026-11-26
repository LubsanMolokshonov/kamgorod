<?php
/**
 * PaymentReconciliation — страховочная сверка платежей с Yookassa.
 *
 * Зачем: вебхук Yookassa — единственный путь подтверждения оплаты. Если он не отработал
 * (фатал в коде, недоступность сервера, недоставленный вебхук) — заказ навсегда остаётся
 * `pending`, хотя деньги списаны. Страница возврата и /api/check-payment.php только читают
 * статус из БД и не опрашивают Yookassa, поэтому сами не чинят. Так баг неймспейса
 * PaymentStatus 4 дня терял оплаты подписок (19-22.06.2026).
 *
 * Этот класс берёт «зависшие» pending-заказы с yookassa_payment_id, спрашивает у Yookassa
 * (источник истины) реальный статус и идемпотентно доводит дело до конца:
 *   - succeeded, а у нас pending → активировать подписку / выдать документы (как вебхук);
 *   - canceled → пометить заказ failed.
 * Любое восстановление шлёт алерт в Telegram, чтобы человек знал, что вебхук что-то пропустил.
 *
 * Идемпотентность: SubscriptionService::activate идемпотентна по order_id; fulfillOrderItems
 * безопасна к повтору; перед выдачей повторно проверяем, что заказ всё ещё pending.
 *
 * Токены покрываются отдельным методом reconcileTokens(): они не создают строку в `orders`
 * (живут только в metadata Yookassa-платежа), поэтому сверка идёт от списка succeeded-платежей
 * Yookassa, фильтруя metadata.payment_type='tokens' и доначисляя некредитованные
 * (идемпотентность по token_transactions.payment_id, как в вебхуке).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/CourseCheckout.php';
require_once __DIR__ . '/CoursePaymentEvent.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/UserTokens.php';
require_once __DIR__ . '/TokenPackage.php';
require_once __DIR__ . '/MaxCourseRecommendationChain.php';
require_once __DIR__ . '/../includes/order-fulfillment.php';
require_once __DIR__ . '/../includes/email-helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

class PaymentReconciliation
{
    /** @var PDO */
    private $pdo;
    /** @var Database */
    private $db;
    /** @var \YooKassa\Client */
    private $client;
    /** @var callable */
    private $log;

    public function __construct(PDO $pdo, ?callable $log = null)
    {
        $this->pdo = $pdo;
        $this->db  = new Database($pdo);
        $this->log = $log ?? static function (string $level, string $msg): void {};

        $this->client = new \YooKassa\Client();
        $this->client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);
    }

    /**
     * Сверить зависшие pending-заказы со «свежим» возрастом 10 мин … 7 дней.
     * Нижняя граница — чтобы не дёргать только что созданные (вебхук ещё в пути).
     * Верхняя — Yookassa-платёж по неоплаченному заказу всё равно протухает (canceled).
     */
    public function reconcile(int $minAgeMinutes = 10, int $maxAgeDays = 7): array
    {
        $stats = ['checked' => 0, 'recovered' => 0, 'failed_marked' => 0, 'still_pending' => 0, 'errors' => 0];

        $rows = $this->db->query(
            "SELECT o.id, o.order_number, o.user_id, o.subscription_plan_id, o.subscription_period,
                    o.final_amount, o.payment_status, o.yookassa_payment_id,
                    (SELECT MIN(oi.course_enrollment_id) FROM order_items oi WHERE oi.order_id = o.id) AS enrollment_id
               FROM orders o
               LEFT JOIN course_checkout_payments a ON a.order_id = o.id
              WHERE (o.payment_status = 'pending' OR (
                        o.payment_status = 'processing'
                        AND EXISTS (SELECT 1 FROM order_items oi WHERE oi.order_id = o.id AND oi.course_enrollment_id IS NOT NULL)
                    ))
                AND o.yookassa_payment_id IS NOT NULL AND o.yookassa_payment_id <> ''
                AND ((a.attempt_started_at IS NULL
                      AND o.created_at BETWEEN (NOW() - INTERVAL ? DAY) AND (NOW() - INTERVAL ? MINUTE))
                     OR (a.attempt_started_at IS NOT NULL
                         AND a.attempt_started_at BETWEEN (UTC_TIMESTAMP() - INTERVAL ? DAY)
                                                      AND (UTC_TIMESTAMP() - INTERVAL ? MINUTE)))
              ORDER BY o.id",
            [$maxAgeDays, $minAgeMinutes, $maxAgeDays, $minAgeMinutes]
        );

        foreach ($rows as $o) {
            $stats['checked']++;
            try {
                $payment = $this->client->getPaymentInfo($o['yookassa_payment_id']);
                $enrollmentId = (int)$o['enrollment_id'];
                $apply = fn(): string => $this->applyCurrentPayment($o, $payment);
                $result = $enrollmentId > 0
                    ? CourseCheckout::withEnrollmentLock($this->pdo, $enrollmentId, $apply)
                    : $apply();
                $stats[$result]++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->emit('ERROR', "order {$o['order_number']} ({$o['yookassa_payment_id']}): " . $e->getMessage());
            }
        }

        $this->reconcileUnattachedPayments($minAgeMinutes, $maxAgeDays, $stats);
        return $stats;
    }

    private function applyCurrentPayment(array $order, object $payment): string
    {
        $current = $this->db->queryOne(
            "SELECT payment_status FROM orders WHERE id = ? AND yookassa_payment_id = ?",
            [$order['id'], $payment->getId()]
        );
        if (!$current || !in_array($current['payment_status'], ['pending', 'processing'], true)) {
            return 'still_pending';
        }
        if (!empty($order['enrollment_id']) && !CoursePaymentEvent::matchesAmount($order, $payment)) {
            throw new RuntimeException('Сумма оплаты курса отличается от заказа');
        }
        if (!empty($order['enrollment_id']) && $payment->getStatus() === \YooKassa\Model\PaymentStatus::WAITING_FOR_CAPTURE) {
            (new Order($this->pdo))->updateActiveYookassaStatus((int)$order['id'], $payment->getId(), 'processing');
        }
        if ($payment->getStatus() === \YooKassa\Model\PaymentStatus::SUCCEEDED) {
            $refunded = empty($order['enrollment_id']) ? null : $payment->getRefundedAmount();
            if ($refunded && $refunded->getCurrency() === 'RUB'
                && (string)$refunded->getValue() === number_format((float)$order['final_amount'], 2, '.', '')) {
                $updated = (new Order($this->pdo))->updateActiveYookassaStatus((int)$order['id'], $payment->getId(), 'refunded');
                return $updated ? 'recovered' : 'still_pending';
            }
            return $this->heal($order, $payment) ? 'recovered' : 'still_pending';
        }
        if ($payment->getStatus() === \YooKassa\Model\PaymentStatus::CANCELED
            && (new Order($this->pdo))->cancelYookassaPayment((int)$order['id'], $payment->getId())) {
            $this->emit('INFO', "order {$order['order_number']} → failed (Yookassa canceled)");
            return 'failed_marked';
        }
        return 'still_pending';
    }

    /** Восстановить потерянный ответ на создание по платежам магазина и сохранённой попытке. */
    private function reconcileUnattachedPayments(int $minAgeMinutes, int $maxAgeDays, array &$stats): void
    {
        $attempts = $this->db->query(
            "SELECT a.enrollment_id, a.attempt_key, a.attempt_started_at,
                    o.id, o.order_number, o.user_id, o.subscription_plan_id, o.final_amount
               FROM course_checkout_payments a JOIN orders o ON o.id = a.order_id
              WHERE a.attempt_payload IS NOT NULL AND a.attempt_key IS NOT NULL
                AND (o.yookassa_payment_id IS NULL OR o.yookassa_payment_id = '')
                AND o.payment_status IN ('pending', 'processing')
                AND a.attempt_started_at <= (UTC_TIMESTAMP() - INTERVAL ? MINUTE)
                AND a.attempt_started_at >= (UTC_TIMESTAMP() - INTERVAL ? DAY)
              ORDER BY a.attempt_started_at ASC LIMIT 200",
            [$minAgeMinutes, $maxAgeDays]
        );
        $cartAttempts = $this->db->query(
            "SELECT o.id, o.created_at AS attempt_started_at
               FROM cart_payment_attempts a JOIN orders o ON o.id = a.order_id
              WHERE (o.yookassa_payment_id IS NULL OR o.yookassa_payment_id = '')
                AND o.payment_status = 'pending'
                AND o.created_at BETWEEN (NOW() - INTERVAL ? DAY) AND (NOW() - INTERVAL ? MINUTE)
              ORDER BY o.created_at LIMIT 200",
            [$maxAgeDays, $minAgeMinutes]
        );
        if (!$attempts && !$cartAttempts) {
            return;
        }

        $unattached = array_column($attempts, null, 'attempt_key');
        $cartOrders = array_column($cartAttempts, null, 'id');
        $since = min(array_column(array_merge($attempts, $cartAttempts), 'attempt_started_at'));
        $filter = ['created_at_gte' => str_replace(' ', 'T', $since) . 'Z', 'limit' => 100];
        $orderObj = new Order($this->pdo);
        try {
            for ($page = 0; $page < 10; $page++) {
                $response = $this->client->getPayments($filter);
                foreach ($response->getItems() as $payment) {
                    $meta = $payment->getMetadata();
                    $cartOrderId = (int)($meta['order_id'] ?? 0);
                    if (($meta['payment_type'] ?? '') === 'cart' && isset($cartOrders[$cartOrderId])) {
                        // Штатный вебхук или следующий проход со связанным ID доведёт выдачу до конца.
                        if ($orderObj->attachVerifiedCartPayment($payment)) {
                            unset($cartOrders[$cartOrderId]);
                            $stats['checked']++;
                            if ($payment->getStatus() === \YooKassa\Model\PaymentStatus::CANCELED
                                && $orderObj->cancelYookassaPayment($cartOrderId, $payment->getId())) {
                                $stats['failed_marked']++;
                            } else {
                                $stats['still_pending']++;
                            }
                        }
                        continue;
                    }
                    $key = CoursePaymentEvent::attemptKey($payment);
                    if (!isset($unattached[$key])) {
                        continue;
                    }
                    $order = $unattached[$key];
                    $enrollmentId = (int)$order['enrollment_id'];
                    $result = CourseCheckout::withEnrollmentLock($this->pdo, $enrollmentId, function () use ($enrollmentId, $order, $payment): string {
                        if (!CoursePaymentEvent::attachVerified($this->pdo, $enrollmentId, $payment)) {
                            return 'still_pending';
                        }
                        return $this->applyCurrentPayment($order, $payment);
                    });
                    $stats['checked']++;
                    $stats[$result]++;
                    unset($unattached[$key]);
                }
                $filter['cursor'] = $response->getNextCursor();
                if (!$filter['cursor'] || (!$unattached && !$cartOrders)) {
                    break;
                }
            }
            if (($unattached || $cartOrders) && !empty($filter['cursor'])) {
                $this->emit('WARN', 'Сверка заказов без ID ЮKassa достигла лимита страниц');
            }
        } catch (Throwable $e) {
            $stats['errors']++;
            $this->emit('ERROR', 'Сверка заказов без ID ЮKassa: ' . get_class($e));
        }
    }

    /**
     * Довести оплаченный, но зависший заказ до конца. Возвращает true, если реально починили.
     * Повторно проверяет статус прямо перед выдачей — защита от гонки с вебхуком.
     */
    private function heal(array $o, $payment = null): bool
    {
        $orderId = (int)$o['id'];

        $cur = $this->db->queryOne("SELECT payment_status FROM orders WHERE id = ?", [$orderId]);
        if (!$cur || !in_array($cur['payment_status'], ['pending', 'processing'], true)) {
            return false; // вебхук успел раньше
        }

        $orderObj = new Order($this->pdo);

        if (!empty($o['subscription_plan_id'])) {
            // --- Подписка (повтор ветки subscription из вебхука) ---
            // Сначала activate() (своя транзакция, идемпотентна по order_id), потом помечаем
            // заказ succeeded: если activate упадёт — заказ останется pending и следующий
            // прогон повторит. НЕ оборачивать в beginTransaction (activate сделает свою).
            // Достаём сохранённую карту из платежа — чтобы автопродление не «потерялось»,
            // если вебхук пропустил первичный платёж (паритет с api/webhook/yookassa.php).
            $savedPm = $payment ? SubscriptionService::extractSavedPaymentMethod($payment)
                                : ['id' => null, 'last4' => null, 'type' => null];
            $svc   = new SubscriptionService($this->pdo);
            $subId = $svc->activate(
                (int)$o['user_id'],
                (int)$o['subscription_plan_id'],
                (string)($o['subscription_period'] ?: 'monthly'),
                $orderId,
                $savedPm['id'],
                $savedPm['last4'],
                $savedPm['type']
            );
            $orderObj->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));
            $this->bestEffort(fn() => sendSubscriptionActivatedEmail((int)$o['user_id'], $subId));
            $this->emit('RECOVER', "subscription order {$o['order_number']} → sub #{$subId} (user {$o['user_id']})");
            $this->alertRecovered($o, "подписка активирована (sub #{$subId})");
        } else {
            // --- Обычный заказ: выдать документы единым движком (как вебхук) ---
            $log = $this->log;
            $source = empty($o['enrollment_id']) ? 'reconcile' : 'reconcile_course_payment';
            fulfillOrderItems($this->pdo, $orderId, $source, static function (string $l, string $m) use ($log): void {
                $log($l, $m);
            });
            $this->bestEffort(fn() => (new MaxCourseRecommendationChain($this->pdo))->schedule($orderId));
            $this->emit('RECOVER', "order {$o['order_number']} дофулфилен (документы выданы)");
            $this->alertRecovered($o, "заказ дофулфилен (документы выданы)");
        }

        return true;
    }

    /**
     * Сверка покупок токенов. У токенов нет строки в `orders`, поэтому идём от списка
     * succeeded-платежей Yookassa за окно (по умолчанию 2 дня — cron каждые 15 мин ловит
     * новое быстро; для глубокого catch-up можно вызвать с большим maxAgeDays), фильтруем
     * metadata.payment_type='tokens' и доначисляем то, что не попало в token_transactions.
     * Пагинация капится maxPages, чтобы не зациклиться на большом объёме.
     */
    public function reconcileTokens(int $maxAgeDays = 2, int $maxPages = 25): array
    {
        $stats = ['scanned' => 0, 'token_payments' => 0, 'recovered' => 0, 'already' => 0, 'errors' => 0, 'capped' => false];
        $gte = gmdate('Y-m-d\TH:i:s.000\Z', time() - $maxAgeDays * 86400);
        $cursor = null;
        $page = 0;

        do {
            $filter = ['status' => 'succeeded', 'created_at_gte' => $gte, 'limit' => 100];
            if ($cursor) $filter['cursor'] = $cursor;

            $resp  = $this->client->getPayments($filter);
            $items = $resp ? $resp->getItems() : [];

            foreach ($items as $p) {
                $stats['scanned']++;
                try {
                    $meta = $this->metaToArray($p->getMetadata());
                    if (($meta['payment_type'] ?? null) !== 'tokens') continue;

                    $stats['token_payments']++;
                    $pid = $p->getId();

                    $already = $this->db->queryOne(
                        "SELECT id FROM token_transactions WHERE payment_id = ? AND reason = 'purchase' LIMIT 1",
                        [$pid]
                    );
                    if ($already) { $stats['already']++; continue; }

                    $this->creditTokens($pid, $meta, (float)$p->getAmount()->getValue(), (string)$p->getAmount()->getCurrency());
                    $stats['recovered']++;
                } catch (\Throwable $e) {
                    $stats['errors']++;
                    $this->emit('ERROR', "token payment {$p->getId()}: " . $e->getMessage());
                }
            }

            $cursor = $resp ? $resp->getNextCursor() : null;
            $page++;
            if ($page >= $maxPages && $cursor) {
                $stats['capped'] = true;
                $this->emit('WARN', "token sweep остановлен на лимите {$maxPages} страниц — увеличьте окно/лимит для catch-up");
                break;
            }
        } while ($cursor);

        return $stats;
    }

    /** Начислить токены за оплаченный, но не кредитованный платёж (повтор ветки tokens вебхука). */
    private function creditTokens(string $paymentId, array $meta, float $amountRub, string $currency): void
    {
        $userId    = (int)($meta['user_id'] ?? 0);
        $packageId = (int)($meta['package_id'] ?? 0);
        if ($userId <= 0 || $packageId <= 0) {
            throw new RuntimeException("битые metadata токенов (user_id/package_id)");
        }

        $packageObj = new TokenPackage($this->pdo);
        $package = $packageObj->getById($packageId);
        if (!$package) {
            throw new RuntimeException("token package #{$packageId} не найден");
        }
        $totalTokens = $packageObj->totalTokens($package);

        $tokens = new UserTokens($this->pdo);
        $txnId = $tokens->credit($userId, $totalTokens, 'purchase', [
            'package_id'   => $packageId,
            'payment_id'   => $paymentId,
            'notes'        => 'Yookassa ' . $amountRub . ' ' . $currency . ' (reconcile)',
            'amount_paid'  => $amountRub,
            'utm_source'   => $meta['utm_source']   ?? null,
            'utm_medium'   => $meta['utm_medium']   ?? null,
            'utm_campaign' => $meta['utm_campaign'] ?? null,
            'utm_content'  => $meta['utm_content']  ?? null,
            'utm_term'     => $meta['utm_term']     ?? null,
        ]);

        $this->emit('RECOVER', "tokens: user={$userId} +{$totalTokens} pkg={$packageId} txn={$txnId} payment={$paymentId}");
        $this->bestEffort(function () use ($paymentId, $userId, $totalTokens, $amountRub) {
            TelegramNotifier::instance($this->pdo)->alert(
                'tokens_reconciled_' . substr(md5($paymentId), 0, 10),
                '[Reconcile] Вебхук пропустил оплату токенов — начислено',
                ['payment' => $paymentId, 'user_id' => (string)$userId, 'tokens' => (string)$totalTokens, 'amount' => (string)$amountRub],
                'warning'
            );
        });
    }

    private function metaToArray($metadata): array
    {
        if (!$metadata) return [];
        if (is_array($metadata)) return $metadata;
        if (method_exists($metadata, 'toArray')) return $metadata->toArray();
        return (array)$metadata;
    }

    private function alertRecovered(array $o, string $what): void
    {
        $this->bestEffort(function () use ($o, $what) {
            TelegramNotifier::instance($this->pdo)->alert(
                'payment_reconciled_' . $o['id'],
                '[Reconcile] Вебхук пропустил оплату — восстановлено',
                [
                    'order'   => (string)$o['order_number'],
                    'user_id' => (string)$o['user_id'],
                    'amount'  => (string)$o['final_amount'],
                    'action'  => $what,
                    'payment' => (string)$o['yookassa_payment_id'],
                ],
                'warning'
            );
        });
    }

    private function bestEffort(callable $fn): void
    {
        try { $fn(); } catch (\Throwable $e) { $this->emit('WARN', 'best-effort: ' . $e->getMessage()); }
    }

    private function emit(string $level, string $msg): void
    {
        ($this->log)($level, $msg);
    }
}

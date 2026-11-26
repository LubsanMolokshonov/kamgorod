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
require_once __DIR__ . '/SubscriptionPayments.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/UserTokens.php';
require_once __DIR__ . '/TokenPackage.php';
require_once __DIR__ . '/MaxCourseRecommendationChain.php';
require_once __DIR__ . '/../includes/paid-order.php';
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

    public function __construct(PDO $pdo, ?callable $log = null, ?object $client = null)
    {
        $this->pdo = $pdo;
        $this->db  = new Database($pdo);
        $this->log = $log ?? static function (string $level, string $msg): void {};

        $this->client = $client ?? new \YooKassa\Client();
        if ($client === null) {
            $this->client->setAuth(YOOKASSA_SHOP_ID, YOOKASSA_SECRET_KEY);
        }
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
              WHERE o.payment_status IN ('pending','processing')
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

        $covered = $this->db->query(
            "SELECT o.id FROM orders o
             LEFT JOIN payment_reconciliation_schedule q ON q.order_id=o.id AND q.task='covered'
             WHERE o.payment_status='pending' AND o.final_amount=0 AND o.subscription_plan_id IS NULL
               AND COALESCE(o.yookassa_payment_id,'')=''
               AND o.created_at < NOW() - INTERVAL ? MINUTE
               AND (q.next_check_at IS NULL OR q.next_check_at<=UTC_TIMESTAMP())
               AND EXISTS (SELECT 1 FROM order_items i WHERE i.order_id=o.id AND i.covered_by_subscription=1)
             ORDER BY COALESCE(q.next_check_at,TIMESTAMPADD(SECOND,UNIX_TIMESTAMP(o.created_at)-UNIX_TIMESTAMP(),UTC_TIMESTAMP())),o.id LIMIT 100", [$minAgeMinutes]
        );
        foreach ($covered as $row) {
            $stats['checked']++;
            try {
                // Резервируем время следующего обхода до генерации, включая аварийное падение на PDF.
                $this->db->execute("REPLACE INTO payment_reconciliation_schedule(order_id,task,next_check_at)
                    VALUES (?,'covered',UTC_TIMESTAMP()+INTERVAL 15 MINUTE)", [$row['id']]);
                $result = fulfillOrderItems($this->pdo, (int)$row['id'], 'subscription', $this->log);
                if ($result['applied']) {
                    $stats['recovered']++;
                    $this->db->execute("DELETE FROM payment_reconciliation_schedule WHERE order_id=? AND task='covered'", [$row['id']]);
                }
            } catch (Throwable $error) {
                $stats['errors']++;
                $this->emit('ERROR', 'Выдача по подписке, заказ ' . $row['id'] . ': ' . $error->getMessage());
            }
        }
        $this->reconcileUnattachedPayments($minAgeMinutes, $maxAgeDays, $stats);
        (new SubscriptionPayments($this->pdo, $this->client))->reconcile($stats);
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
        if (!CoursePaymentEvent::matchesAmount($order, $payment)) {
            throw new RuntimeException('Сумма платежа отличается от заказа');
        }
        if (!empty($order['enrollment_id']) && $payment->getStatus() === \YooKassa\Model\PaymentStatus::WAITING_FOR_CAPTURE) {
            (new Order($this->pdo))->updateActiveYookassaStatus((int)$order['id'], $payment->getId(), 'processing');
        }
        if ($payment->getStatus() === \YooKassa\Model\PaymentStatus::SUCCEEDED) {
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
            "SELECT a.*,q.scan_cursor,q.scan_until FROM (
                SELECT o.id, o.order_number,o.user_id,o.subscription_plan_id,o.final_amount,a.enrollment_id,
                       a.attempt_key,a.attempt_started_at,'lookup_course' AS task
                FROM course_checkout_payments a JOIN orders o ON o.id=a.order_id
                WHERE a.attempt_payload IS NOT NULL AND a.attempt_key IS NOT NULL
                  AND COALESCE(o.yookassa_payment_id,'')='' AND o.payment_status IN ('pending','processing')
                  AND a.attempt_started_at BETWEEN UTC_TIMESTAMP()-INTERVAL ? DAY AND UTC_TIMESTAMP()-INTERVAL ? MINUTE
                UNION ALL
                SELECT o.id,o.order_number,o.user_id,o.subscription_plan_id,o.final_amount,NULL,
                       a.payload_hash,TIMESTAMPADD(SECOND,UNIX_TIMESTAMP(o.created_at)-UNIX_TIMESTAMP(),UTC_TIMESTAMP()),'lookup_cart'
                FROM cart_payment_attempts a JOIN orders o ON o.id=a.order_id
                WHERE COALESCE(o.yookassa_payment_id,'')='' AND o.payment_status='pending'
                  AND o.created_at BETWEEN NOW()-INTERVAL ? DAY AND NOW()-INTERVAL ? MINUTE
             ) a
             LEFT JOIN payment_reconciliation_schedule q
               ON q.order_id=a.id AND q.task=a.task AND q.attempt_key=a.attempt_key
             WHERE q.next_check_at IS NULL OR q.next_check_at<=UTC_TIMESTAMP()
             ORDER BY COALESCE(q.next_check_at,a.attempt_started_at),a.id LIMIT 10",
            [$maxAgeDays,$minAgeMinutes,$maxAgeDays,$minAgeMinutes]
        );
        foreach ($attempts as $order) {
            $stats['checked']++;
            try {
                $stats[$this->scanAttemptPage($order)]++;
            } catch (Throwable $e) {
                $stats['errors']++;
                $this->emit('ERROR', 'Сверка непривязанного платежа заказа #' . $order['id'] . ': ' . get_class($e));
            }
        }
    }

    /** Один GET магазина. При пагинации состав фильтра сохраняется до исчерпания списка. */
    private function scanAttemptPage(array $order): string
    {
        $started = strtotime($order['attempt_started_at'] . ' UTC');
        // За рамками 24 часов приложение не отправляет попытку с тем же ключом.
        $until = $order['scan_until'] ?? gmdate('Y-m-d H:i:s', min(time(), $started + 86400));
        $cursor = $order['scan_cursor'];
        $this->db->execute(
            'REPLACE INTO payment_reconciliation_schedule(order_id,task,attempt_key,next_check_at,scan_until,scan_cursor)
             VALUES (?,?,?,UTC_TIMESTAMP()+INTERVAL 15 MINUTE,?,?)',
            [$order['id'],$order['task'],$order['attempt_key'],$until,$cursor]
        );
        $filter = ['limit'=>100, 'created_at_gte'=>gmdate('c', $started), 'created_at_lte'=>gmdate('c', strtotime($until . ' UTC'))];
        if ($cursor !== null) {
            $filter['cursor'] = $cursor;
        }
        $page = $this->client->getPayments($filter);
        foreach ($page->getItems() as $payment) {
            $meta = $payment->getMetadata();
            if ((int)($meta['order_id'] ?? 0) !== (int)$order['id']) {
                continue;
            }
            if ($order['task'] === 'lookup_course') {
                if (CoursePaymentEvent::attemptKey($payment) !== $order['attempt_key']) {
                    continue;
                }
                $enrollmentId = (int)$order['enrollment_id'];
                $result = CourseCheckout::withEnrollmentLock($this->pdo,$enrollmentId,function () use ($enrollmentId,$order,$payment) {
                    if (!CoursePaymentEvent::attachVerified($this->pdo,$enrollmentId,$payment)) {
                        throw new DomainException('Не подтверждена привязка оплаты курса');
                    }
                    return $this->applyCurrentPayment($order + ['yookassa_payment_id'=>$payment->getId()],$payment);
                });
            } else {
                if (($meta['payment_type'] ?? '') !== 'cart') {
                    continue;
                }
                if (!(new Order($this->pdo))->attachVerifiedCartPayment($payment)) {
                    throw new DomainException('Не подтверждена привязка оплаты корзины');
                }
                $result = $this->applyCurrentPayment($order + ['yookassa_payment_id'=>$payment->getId()],$payment);
            }
            $this->db->execute('DELETE FROM payment_reconciliation_schedule WHERE order_id=? AND task=? AND attempt_key=?',
                [$order['id'],$order['task'],$order['attempt_key']]);
            return $result;
        }
        $next = $page->getNextCursor();
        if ($next && $next === $cursor) {
            $this->emit('WARN','Магазин повторил курсор сверки заказа #' . $order['id']);
            $next = null;
        }
        $delay = $next ? 60 : (time() - $started < 86400 ? 900 : 86400);
        $this->db->execute(
            'UPDATE payment_reconciliation_schedule SET next_check_at=?, scan_cursor=?, scan_until=?
             WHERE order_id=? AND task=? AND attempt_key=?',
            [gmdate('Y-m-d H:i:s', time()+$delay),$next,$next ? $until : null,$order['id'],$order['task'],$order['attempt_key']]
        );
        return 'still_pending';
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
            $result = (new SubscriptionPayments($this->pdo, $this->client))->accept($payment, $orderId);
            if (!$result['applied']) {
                return false;
            }
            $this->alertRecovered($o, 'подписка активирована');
        } else {
            // --- Обычный заказ: выдать документы единым движком (как вебхук) ---
            $log = $this->log;
            $source = empty($o['enrollment_id']) ? 'reconcile' : 'reconcile_course_payment';
            if (!settlePaidOrder($this->pdo, $orderObj->getById($orderId), $payment, $source, $log)) {
                return false;
            }
            $status = $this->db->queryOne("SELECT payment_status FROM orders WHERE id=?", [$orderId]);
            $outcome = $status['payment_status'] === 'refunded' ? 'подтверждён полный возврат' : 'покупка выдана';
            $this->emit('RECOVER', "order {$o['order_number']}: {$outcome}");
            $this->alertRecovered($o, $outcome);
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
        $stats = ['scanned' => 0, 'token_payments' => 0, 'refunded' => 0, 'recovered' => 0, 'already' => 0, 'errors' => 0, 'capped' => false];
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
                    if (PaymentRefundAccounting::isFullyRefunded($p)) {
                        $stats['refunded']++;
                        continue;
                    }
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

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
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/UserTokens.php';
require_once __DIR__ . '/TokenPackage.php';
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
            "SELECT id, order_number, user_id, subscription_plan_id, subscription_period,
                    final_amount, payment_status, yookassa_payment_id
               FROM orders
              WHERE payment_status = 'pending'
                AND yookassa_payment_id IS NOT NULL AND yookassa_payment_id <> ''
                AND created_at <= (NOW() - INTERVAL ? MINUTE)
                AND created_at >= (NOW() - INTERVAL ? DAY)
              ORDER BY id",
            [$minAgeMinutes, $maxAgeDays]
        );

        foreach ($rows as $o) {
            $stats['checked']++;
            try {
                $payment = $this->client->getPaymentInfo($o['yookassa_payment_id']);
                $status  = $payment->getStatus();

                if ($status === \YooKassa\Model\PaymentStatus::SUCCEEDED) {
                    if ($this->heal($o)) $stats['recovered']++;
                    else $stats['still_pending']++; // уже обработан кем-то в гонке
                } elseif ($status === \YooKassa\Model\PaymentStatus::CANCELED) {
                    (new Order($this->pdo))->updatePaymentStatus((int)$o['id'], 'failed');
                    $this->emit('INFO', "order {$o['order_number']} → failed (Yookassa canceled)");
                    $stats['failed_marked']++;
                } else {
                    $stats['still_pending']++; // pending / waiting_for_capture — ждём
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->emit('ERROR', "order {$o['order_number']} ({$o['yookassa_payment_id']}): " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Довести оплаченный, но зависший заказ до конца. Возвращает true, если реально починили.
     * Повторно проверяет статус прямо перед выдачей — защита от гонки с вебхуком.
     */
    private function heal(array $o): bool
    {
        $orderId = (int)$o['id'];

        $cur = $this->db->queryOne("SELECT payment_status FROM orders WHERE id = ?", [$orderId]);
        if (!$cur || $cur['payment_status'] === 'succeeded') {
            return false; // вебхук успел раньше
        }

        $orderObj = new Order($this->pdo);

        if (!empty($o['subscription_plan_id'])) {
            // --- Подписка (повтор ветки subscription из вебхука) ---
            $orderObj->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));
            $svc   = new SubscriptionService($this->pdo);
            $subId = $svc->activate(
                (int)$o['user_id'],
                (int)$o['subscription_plan_id'],
                (string)($o['subscription_period'] ?: 'monthly'),
                $orderId,
                null
            );
            $this->bestEffort(fn() => sendSubscriptionActivatedEmail((int)$o['user_id'], $subId));
            $this->emit('RECOVER', "subscription order {$o['order_number']} → sub #{$subId} (user {$o['user_id']})");
            $this->alertRecovered($o, "подписка активирована (sub #{$subId})");
        } else {
            // --- Обычный заказ: выдать документы единым движком (как вебхук) ---
            $orderObj->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));
            $log = $this->log;
            fulfillOrderItems($this->pdo, $orderId, 'reconcile', static function (string $l, string $m) use ($log): void {
                $log($l, $m);
            });
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

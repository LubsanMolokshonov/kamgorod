<?php
/**
 * SubscriptionRenewal — рекуррентные автосписания подписок (Этап 2).
 *
 * Зачем: подписка с auto_renew=1 хранит сохранённый метод оплаты Yookassa
 * (yookassa_payment_method_id). За LEAD_DAYS до конца периода этот класс создаёт
 * заказ-подписку и инициирует рекуррентный платёж (createPayment с payment_method_id,
 * без confirmation — merchant-initiated). Активация (продление expires_at) идёт через
 * SubscriptionService::activate() — идемпотентна по order_id, поэтому повтор webhook'ом
 * или reconcile-кроном дубля не создаёт.
 *
 * Dunning: на каждую попытку растёт renewal_attempt_count (бэк-офф 12 ч между попытками),
 * после MAX_ATTEMPTS неудач — письмо «не удалось списать» и подписка тихо истекает
 * (getActiveSubscription фильтрует expires_at > NOW(), доступ прекращается сам).
 *
 * Списываем за LEAD_DAYS ДО конца: activate стыкует период через GREATEST(expires_at, NOW()),
 * поэтому досрочное списание не съедает оплаченные дни.
 *
 * По образцу PaymentReconciliation (тонкий cron + класс с логикой и Yookassa-клиентом).
 */

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Order.php';
require_once __DIR__ . '/SubscriptionService.php';
require_once __DIR__ . '/TelegramNotifier.php';
require_once __DIR__ . '/../includes/email-helper.php';
require_once __DIR__ . '/../vendor/autoload.php';

class SubscriptionRenewal
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
     * Обработать подписки, которым пора продлеваться. Возвращает статистику.
     */
    public function run(): array
    {
        $stats = ['checked' => 0, 'charged' => 0, 'failed' => 0, 'errors' => 0];

        $maxAttempts = (int)(defined('SUBSCRIPTION_RENEW_MAX_ATTEMPTS') ? SUBSCRIPTION_RENEW_MAX_ATTEMPTS : 3);
        $leadDays    = (int)(defined('SUBSCRIPTION_RENEW_LEAD_DAYS') ? SUBSCRIPTION_RENEW_LEAD_DAYS : 1);

        $rows = $this->db->query(
            "SELECT us.id, us.user_id, us.plan_id, us.period, us.yookassa_payment_method_id,
                    us.expires_at, us.renewal_attempt_count,
                    p.name AS plan_name, p.slug AS plan_slug, p.price_monthly, p.price_yearly,
                    u.email AS user_email, u.pricing_variant AS user_variant
               FROM user_subscriptions us
               JOIN subscription_plans p ON p.id = us.plan_id
               JOIN users u ON u.id = us.user_id
              WHERE us.status = 'active'
                AND us.auto_renew = 1
                AND us.yookassa_payment_method_id IS NOT NULL AND us.yookassa_payment_method_id <> ''
                AND us.expires_at IS NOT NULL
                AND us.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
                AND us.renewal_attempt_count < ?
                AND (us.last_renewal_attempt_at IS NULL
                     OR us.last_renewal_attempt_at < DATE_SUB(NOW(), INTERVAL 12 HOUR))
                AND u.email IS NOT NULL AND u.email <> ''
              ORDER BY us.expires_at ASC
              LIMIT 50",
            [$leadDays, $maxAttempts]
        );

        foreach ($rows as $sub) {
            $stats['checked']++;
            try {
                $ok = $this->charge($sub, $maxAttempts);
                $ok ? $stats['charged']++ : $stats['failed']++;
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->emit('ERROR', "sub #{$sub['id']} (user {$sub['user_id']}): " . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Списать с сохранённой карты и (при успехе) продлить. Возвращает true, если списание
     * прошло (succeeded). Любой не-succeeded исход считается неудачной попыткой.
     */
    private function charge(array $sub, int $maxAttempts): bool
    {
        $subId    = (int)$sub['id'];
        $userId   = (int)$sub['user_id'];
        $planId   = (int)$sub['plan_id'];
        $period   = $sub['period'] === 'yearly' ? 'yearly' : 'monthly';
        $priceRub = $period === 'yearly' ? (float)$sub['price_yearly'] : (float)$sub['price_monthly'];
        $email    = (string)$sub['user_email'];
        $attemptNo = (int)$sub['renewal_attempt_count'] + 1;

        if ($priceRub <= 0) {
            throw new RuntimeException("некорректная цена тарифа #{$planId}");
        }

        $periodLabel = $period === 'yearly' ? 'год' : 'месяц';
        $description = "Продление подписки «{$sub['plan_name']}» на fgos.pro ({$periodLabel})";

        // 1) Заказ-подписку (pending) — как в ajax/create-subscription-payment.php, без order_items.
        $orderNumber = Order::generateOrderNumber();
        $orderId = (int)$this->db->insert('orders', [
            'user_id'              => $userId,
            'order_number'         => $orderNumber,
            'total_amount'         => $priceRub,
            'discount_amount'      => 0,
            'final_amount'         => $priceRub,
            'payment_status'       => 'pending',
            'subscription_plan_id' => $planId,
            'subscription_period'  => $period,
        ]);
        // Атрибуция A/B: рекуррент идёт из крона (нет сессии/cookie), поэтому штампуем
        // ЗАФИКСИРОВАННЫЙ за пользователем вариант напрямую, а не PricingMode::getVariant()
        // (тот в CLI назначил бы случайный и закэшировал на весь прогон).
        if (in_array($sub['user_variant'] ?? null, ['A', 'B'], true)) {
            $this->db->execute(
                "UPDATE orders SET pricing_variant = ? WHERE id = ?",
                [$sub['user_variant'], $orderId]
            );
        }

        // 2) Отмечаем попытку ДО обращения к Yookassa — защита от повторного списания при
        //    сбое/гонке кронов (следующий прогон не возьмёт раньше чем через 12 ч).
        $this->db->execute(
            "UPDATE user_subscriptions
                SET renewal_attempt_count = renewal_attempt_count + 1, last_renewal_attempt_at = NOW()
              WHERE id = ?",
            [$subId]
        );

        // 3) Рекуррентный платёж: payment_method_id, без confirmation, capture сразу.
        $idempotencyKey = 'subrenew_' . $subId . '_' . $orderId;
        try {
            $payment = $this->client->createPayment(
                [
                    'amount' => [
                        'value' => number_format($priceRub, 2, '.', ''),
                        'currency' => 'RUB',
                    ],
                    'capture' => true,
                    'payment_method_id' => (string)$sub['yookassa_payment_method_id'],
                    'description' => $description,
                    'receipt' => $email ? [
                        'customer' => ['email' => $email],
                        'items' => [[
                            'description' => $description,
                            'quantity' => 1,
                            'amount' => [
                                'value' => number_format($priceRub, 2, '.', ''),
                                'currency' => 'RUB',
                            ],
                            'vat_code' => 1,
                            'payment_mode' => 'full_payment',
                            'payment_subject' => 'service',
                        ]],
                    ] : null,
                    'metadata' => [
                        'payment_type' => 'subscription',
                        'user_id' => $userId,
                        'order_id' => $orderId,
                        'plan_id' => $planId,
                        'plan_slug' => (string)$sub['plan_slug'],
                        'period' => $period,
                        'renewal' => '1',
                    ],
                ],
                $idempotencyKey
            );
        } catch (\Throwable $e) {
            // Отказ на создании (карта истекла, метод недоступен и т.п.). Заказ помечаем failed:
            // у него нет yookassa_payment_id → reconcile его не подберёт.
            (new Order($this->pdo))->updatePaymentStatus($orderId, 'failed');
            $this->onFailed($sub, $attemptNo, $maxAttempts, 'create: ' . $e->getMessage());
            return false;
        }

        (new Order($this->pdo))->updateYookassaDetails($orderId, $payment->getId(), '');
        $status = $payment->getStatus();

        if ($status === \YooKassa\Model\PaymentStatus::SUCCEEDED) {
            // Активируем сразу (идемпотентно по order_id) — даём немедленное продление и сброс
            // счётчика попыток; webhook/reconcile, если придут, увидят succeeded и будут no-op.
            $svc = new SubscriptionService($this->pdo);
            $svc->activate($userId, $planId, $period, $orderId, (string)$sub['yookassa_payment_method_id']);
            (new Order($this->pdo))->updatePaymentStatus($orderId, 'succeeded', date('Y-m-d H:i:s'));
            $this->bestEffort(fn() => sendSubscriptionActivatedEmail($userId, $subId));
            $this->emit('RENEW', "sub #{$subId} (user {$userId}) продлена платежом {$payment->getId()}");
            return true;
        }

        if ($status === \YooKassa\Model\PaymentStatus::CANCELED) {
            (new Order($this->pdo))->updatePaymentStatus($orderId, 'failed');
            $this->onFailed($sub, $attemptNo, $maxAttempts, 'canceled');
            return false;
        }

        // pending / waiting_for_capture: рекуррент обычно succeeded сразу. Если требует
        // confirmation (редкий 3DS) — завершить merchant-initiated нельзя; ждём webhook/следующую
        // попытку. Заказ оставляем pending: reconcile подхватит, если деньги всё же спишутся.
        $this->emit('WAIT', "sub #{$subId} платёж {$payment->getId()} status={$status} — ждём подтверждения");
        return false;
    }

    /**
     * Неудачная попытка. На последней (исчерпали MAX_ATTEMPTS) — письмо клиенту + алерт.
     */
    private function onFailed(array $sub, int $attemptNo, int $maxAttempts, string $reason): void
    {
        $subId  = (int)$sub['id'];
        $userId = (int)$sub['user_id'];
        $this->emit('FAIL', "sub #{$subId} попытка {$attemptNo}/{$maxAttempts}: {$reason}");

        if ($attemptNo >= $maxAttempts) {
            $this->bestEffort(fn() => sendSubscriptionRenewFailedEmail($userId, $subId));
            $this->bestEffort(function () use ($sub, $subId, $userId, $reason) {
                TelegramNotifier::instance($this->pdo)->alert(
                    'sub_renew_failed_' . $subId,
                    '[Подписка] Автопродление не удалось',
                    [
                        'sub_id'  => (string)$subId,
                        'user_id' => (string)$userId,
                        'plan'    => (string)$sub['plan_name'],
                        'reason'  => $reason,
                    ],
                    'warning'
                );
            });
        }
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

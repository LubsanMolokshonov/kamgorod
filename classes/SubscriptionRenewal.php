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
require_once __DIR__ . '/SubscriptionPayments.php';
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
     * Обработать подписки, которым пора продлеваться. Возвращает статистику.
     */
    public function run(): array
    {
        $stats = ['checked' => 0, 'charged' => 0, 'failed' => 0, 'errors' => 0];

        $maxAttempts = (int)(defined('SUBSCRIPTION_RENEW_MAX_ATTEMPTS') ? SUBSCRIPTION_RENEW_MAX_ATTEMPTS : 3);
        $leadDays    = (int)(defined('SUBSCRIPTION_RENEW_LEAD_DAYS') ? SUBSCRIPTION_RENEW_LEAD_DAYS : 1);

        if (!SUBSCRIPTION_AUTORENEW_ENABLED) {
            return $stats;
        }
        $payments = new SubscriptionPayments($this->pdo, $this->client);
        $rows = $this->db->query(
            "SELECT us.id, us.user_id, us.plan_id, us.period, us.yookassa_payment_method_id,
                    us.expires_at, us.renewal_attempt_count,
                    p.name AS plan_name, p.slug AS plan_slug, p.price_monthly, p.price_yearly,
                    u.email AS user_email, u.pricing_variant AS user_variant
               FROM user_subscriptions us
               JOIN subscription_plans p ON p.id = us.plan_id
               JOIN users u ON u.id = us.user_id
               LEFT JOIN subscription_recheck_schedule retry ON retry.task='renewal' AND retry.entity_id=us.id
              WHERE us.status = 'active'
                AND us.auto_renew = 1
                AND us.yookassa_payment_method_id IS NOT NULL AND us.yookassa_payment_method_id <> ''
                AND us.expires_at IS NOT NULL
                AND us.expires_at <= DATE_ADD(NOW(), INTERVAL ? DAY)
                AND us.renewal_attempt_count < ?
                AND (us.last_renewal_attempt_at IS NULL
                     OR us.last_renewal_attempt_at < DATE_SUB(NOW(), INTERVAL 12 HOUR))
                AND u.email IS NOT NULL AND u.email <> ''
                AND (retry.next_check_at IS NULL OR retry.next_check_at<=UTC_TIMESTAMP())
              ORDER BY COALESCE(retry.next_check_at, TIMESTAMPADD(SECOND,
                        TIMESTAMPDIFF(SECOND,NOW(),DATE_SUB(us.expires_at,INTERVAL ? DAY)),UTC_TIMESTAMP())), us.id
              LIMIT 50",
            [$leadDays, $maxAttempts, $leadDays]
        );

        foreach ($rows as $sub) {
            $stats['checked']++;
            try {
                // Сдвиг до запроса: пропуск, таймаут и обрыв процесса не занимают первые 50 мест снова.
                $this->db->execute("REPLACE INTO subscription_recheck_schedule(task,entity_id,next_check_at)
                    VALUES ('renewal',?,?)", [$sub['id'], gmdate('Y-m-d H:i:s', time()+3600)]);
                $result = $payments->renew($sub, $maxAttempts, $leadDays);
                if ($result['status'] === 'succeeded') {
                    $stats['charged']++;
                } elseif ($result['status'] === 'canceled') {
                    $stats['failed']++;
                    $this->onFailed($sub, (int)$sub['renewal_attempt_count'] + 1, $maxAttempts, 'canceled');
                }
            } catch (\Throwable $e) {
                $stats['errors']++;
                $this->emit('ERROR', "sub #{$sub['id']} (user {$sub['user_id']}): " . $e->getMessage());
            }
        }

        return $stats;
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

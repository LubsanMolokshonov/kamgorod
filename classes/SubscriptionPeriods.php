<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

/** Учёт доступа отдельно по каждому оплаченному заказу. Все изменения сериализуются по пользователю. */
final class SubscriptionPeriods
{
    private Database $db;
    private string $rowLock;

    public function __construct(private PDO $pdo)
    {
        $this->db = new Database($pdo);
        $this->rowLock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    /** Месяц и год считаются как MySQL DATE_ADD, в том числе для 29–31 числа. */
    public static function endOfPeriod(string $start, string $period): string
    {
        if (!in_array($period, ['monthly', 'yearly'], true)) {
            throw new InvalidArgumentException('Неизвестный срок подписки');
        }
        $date = new DateTimeImmutable($start, new DateTimeZone('UTC'));
        $first = $date->modify('first day of this month')->modify($period === 'yearly' ? '+1 year' : '+1 month');
        $day = min((int)$date->format('j'), (int)$first->format('t'));
        return $first->setDate((int)$first->format('Y'), (int)$first->format('n'), $day)->format('Y-m-d H:i:s');
    }

    /** Порядок блокировок для подтверждения и возврата всегда одинаков. */
    private function lockUser(int $userId): void
    {
        if (!$this->db->queryOne('SELECT id FROM users WHERE id=?' . $this->rowLock, [$userId])) {
            throw new RuntimeException('Пользователь подписки не найден');
        }
    }

    public function grant(int $userId, int $planId, string $period, ?int $orderId, array $card, bool $renewal = false): array
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockUser($userId);
            $now = (string)$this->pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
            if ($orderId !== null) {
                $order = $this->db->queryOne('SELECT * FROM orders WHERE id=?' . $this->rowLock, [$orderId]);
                if (!$order || (int)$order['user_id'] !== $userId || (int)$order['subscription_plan_id'] !== $planId
                    || $order['subscription_period'] !== $period || $order['payment_status'] === 'refunded') {
                    throw new DomainException('Заказ не соответствует оплаченной подписке');
                }
                $record = $this->db->queryOne('SELECT subscription_id, status FROM subscription_periods WHERE order_id=?', [$orderId]);
                if ($record) {
                    // Старые заказы уже включены в остаток. Если активация прошла перед сбоем
                    // старого обработчика, доводим только статус подтверждённого заказа.
                    if ($record['status'] !== 'refunded') {
                        $this->markPaid($orderId, $now);
                    }
                    $this->pdo->commit();
                    return ['id' => (int)$record['subscription_id'], 'applied' => false];
                }
            }

            $current = $this->db->queryOne(
                "SELECT * FROM user_subscriptions WHERE user_id=? AND status='active'
                  AND (expires_at IS NULL OR expires_at>?) ORDER BY expires_at IS NULL DESC, expires_at DESC, id DESC LIMIT 1" . $this->rowLock,
                [$userId, $now]
            );
            if (!$current && $renewal && $orderId !== null) {
                // Просроченный рекуррент может завершиться после конца периода.
                // Продлеваем исходную строку и сохраняем фактическое состояние её карты.
                $current = $this->db->queryOne(
                    "SELECT s.* FROM subscription_payment_attempts a JOIN user_subscriptions s ON s.id=a.subscription_id
                     WHERE a.order_id=? AND s.user_id=? AND s.plan_id=? AND s.status='active'" . $this->rowLock,
                    [$orderId, $userId, $planId]
                );
            }
            $same = $current && (int)$current['plan_id'] === $planId;
            $start = $same ? max($current['expires_at'] ?? $now, $now) : $now;
            $end = self::endOfPeriod($start, $period);
            $unlimited = $same && $current['expires_at'] === null;
            $fields = ['period' => $period, 'order_id' => $orderId, 'status' => 'active',
                'expires_at' => $unlimited ? null : $end, 'renewal_attempt_count' => 0];
            // Фоновое продление не меняет согласие на списания, в том числе при пришедшем позднее отзыве карты.
            if (!$renewal) {
                $fields += ['auto_renew' => $card['id'] !== null ? 1 : 0, 'yookassa_payment_method_id' => $card['id'],
                    'card_last4' => $card['last4'], 'card_type' => $card['type'], 'cancelled_at' => null];
            }
            $previous = null;
            if ($same) {
                $subId = (int)$current['id'];
                $fields['last_renewed_at'] = $now;
                $this->db->update('user_subscriptions', $fields, 'id=?', [$subId]);
            } else {
                if ($current) {
                    $previous = (int)$current['id'];
                    $this->db->update('user_subscriptions', ['status' => 'expired'], 'id=?', [$previous]);
                }
                $subId = (int)$this->db->insert('user_subscriptions', $fields + [
                    'user_id' => $userId, 'plan_id' => $planId, 'started_at' => $now,
                ]);
            }

            if ($orderId !== null) {
                $this->db->insert('subscription_periods', [
                    'order_id' => $orderId, 'subscription_id' => $subId, 'previous_subscription_id' => $previous,
                    'status' => 'active', 'period' => $period, 'starts_at' => $start, 'ends_at' => $end, 'applied_at' => $now,
                ]);
                $this->markPaid($orderId, $now);
            }
            $this->pdo->commit();
            return ['id' => $subId, 'applied' => true];
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function markPaid(int $orderId, string $now): void
    {
        $this->db->execute("UPDATE orders SET payment_status='succeeded', paid_at=COALESCE(paid_at,?)
                           WHERE id=? AND payment_status NOT IN ('succeeded','refunded')", [$now, $orderId]);
        $this->db->execute('UPDATE subscription_payment_attempts SET scope_key=NULL, payload=NULL, next_check_at=NULL WHERE order_id=?', [$orderId]);
    }

    public function refund(int $orderId, int $userId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->lockUser($userId);
            $order = $this->db->queryOne('SELECT payment_status, subscription_plan_id FROM orders WHERE id=? AND user_id=?' . $this->rowLock, [$orderId, $userId]);
            if (!$order || !$order['subscription_plan_id']) {
                throw new DomainException('Заказ подписки не найден');
            }
            $record = $this->db->queryOne('SELECT * FROM subscription_periods WHERE order_id=?', [$orderId]);
            if ($record && $record['status'] === 'active') {
                $this->db->update('subscription_periods', ['status' => 'refunded'], 'order_id=?', [$orderId]);
                $this->recalculate((int)$record['subscription_id']);
            } elseif ($record && $record['status'] === 'legacy_review' && $order['payment_status'] !== 'refunded') {
                error_log('Возврат исторического заказа подписки требует сверки периода #' . $orderId);
            }
            $this->db->update('orders', ['payment_status' => 'refunded'], 'id=?', [$orderId]);
            $this->db->execute('UPDATE subscription_payment_attempts SET scope_key=NULL, payload=NULL, next_check_at=NULL WHERE order_id=?', [$orderId]);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function recalculate(int $subscriptionId): void
    {
        $sub = $this->db->queryOne('SELECT * FROM user_subscriptions WHERE id=?' . $this->rowLock, [$subscriptionId]);
        $balance = $this->db->queryOne('SELECT * FROM subscription_opening_balances WHERE subscription_id=? AND reconstructed=0', [$subscriptionId]);
        if ($balance && $balance['expires_at'] === null) {
            return; // Бессрочный доступ выдан независимо от этого заказа.
        }
        $now = (string)$this->pdo->query('SELECT CURRENT_TIMESTAMP')->fetchColumn();
        $until = $balance['expires_at'] ?? null;
        $last = $balance['last_order_id'] ?? null;
        $period = $sub['period'];
        $items = $this->db->query("SELECT * FROM subscription_periods WHERE subscription_id=? AND status='active' ORDER BY applied_at, order_id", [$subscriptionId]);
        foreach ($items as $item) {
            // Оставшуюся покупку нельзя израсходовать задним числом, если возвращён
            // более ранний заказ, а оплаченный следующий период ещё даже не начался.
            $availableFrom = min($item['starts_at'], $now);
            $start = max($until ?? $availableFrom, $availableFrom, $item['applied_at']);
            $until = self::endOfPeriod($start, $item['period']);
            $last = (int)$item['order_id'];
            $period = $item['period'];
            $this->db->update('subscription_periods', ['starts_at' => $start, 'ends_at' => $until], 'order_id=?', [$last]);
        }
        $fields = ['expires_at' => $until ?? $now, 'order_id' => $last, 'period' => $period];
        if ($until === null || $until <= $now) {
            $fields += ['status' => 'cancelled', 'auto_renew' => 0, 'cancelled_at' => $now];
        }
        $this->db->update('user_subscriptions', $fields, 'id=?', [$subscriptionId]);

        // После возврата смены тарифа восстановить остающийся оплаченный срок прежнего тарифа.
        if ($sub['status'] === 'active' && isset($fields['status'])) {
            $seen = [];
            while (!isset($seen[$subscriptionId])) {
                $seen[$subscriptionId] = true;
                $previous = $this->db->queryOne(
                    'SELECT s.* FROM subscription_periods p JOIN user_subscriptions s ON s.id=p.previous_subscription_id
                     WHERE p.subscription_id=? AND s.user_id=? ORDER BY p.applied_at, p.order_id LIMIT 1',
                    [$subscriptionId, $sub['user_id']]
                );
                if (!$previous) {
                    break;
                }
                if ($previous['status'] === 'expired' && ($previous['expires_at'] === null || $previous['expires_at'] > $now)) {
                    // Возврат восстанавливает доступ без повторного включения списаний по старой карте.
                    $this->db->update('user_subscriptions', ['status' => 'active', 'auto_renew' => 0], 'id=?', [$previous['id']]);
                    break;
                }
                $subscriptionId = (int)$previous['id'];
            }
        }
    }
}

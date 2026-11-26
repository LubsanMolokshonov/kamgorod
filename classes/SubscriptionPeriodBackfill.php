<?php
declare(strict_types=1);

require_once __DIR__ . '/SubscriptionPeriods.php';

/** Распределяет заказы только если вся цепочка дат воспроизводит сохранённый срок. */
final class SubscriptionPeriodBackfill
{
    public function __construct(private PDO $pdo) {}

    public static function intervals(array $balance, array $orders): ?array
    {
        if (!$orders || !$balance['started_at'] || !$balance['expires_at']
            || (int)end($orders)['id'] !== (int)$balance['last_order_id']) {
            return null;
        }
        $end = $balance['started_at'];
        $result = [];
        foreach ($orders as $index => $order) {
            if (!$order['paid_at'] || $order['payment_status'] !== 'succeeded'
                || $order['ledger_status'] !== 'legacy_review'
                || ($index === 0 && abs(strtotime($order['paid_at'] . ' UTC') - strtotime($end . ' UTC')) > 120)
                || ($index > 0 && $order['paid_at'] > $end)) {
                return null;
            }
            $start = $end;
            $end = SubscriptionPeriods::endOfPeriod($start, $order['subscription_period']);
            $result[(int)$order['id']] = [
                'applied_at' => $index === 0 ? $balance['started_at'] : max($order['paid_at'], $balance['started_at']),
                'starts_at' => $start, 'ends_at' => $end,
            ];
        }
        return $end === $balance['expires_at'] ? $result : null;
    }

    public function run(bool $apply): array
    {
        $db = new Database($this->pdo);
        $stats = ['matched' => 0, 'review' => 0, 'written' => 0];
        $cursor = 0;
        do {
            $balances = $db->query('SELECT * FROM subscription_opening_balances WHERE reconstructed=0 AND subscription_id>? ORDER BY subscription_id LIMIT 100', [$cursor]);
            foreach ($balances as $balance) {
                $cursor = (int)$balance['subscription_id'];
                $this->pdo->beginTransaction();
                try {
                    $rowLock = $apply && $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
                    $db->queryOne('SELECT id FROM users WHERE id=?' . $rowLock, [$balance['user_id']]);
                    $balance = $db->queryOne('SELECT * FROM subscription_opening_balances WHERE subscription_id=? AND reconstructed=0', [$cursor]);
                    if (!$balance) {
                        $this->pdo->commit();
                        continue;
                    }
                    // Возврат после переключения мог уже изменить новый срок. Снимок не обновляем.
                    $orders = $db->query(
                        "SELECT o.id, o.payment_status, o.subscription_period, o.paid_at, p.status AS ledger_status
                         FROM orders o JOIN subscription_periods p ON p.order_id=o.id
                         WHERE o.user_id=? AND o.subscription_plan_id=? AND o.id<=?
                           AND o.id>COALESCE((SELECT MAX(last_order_id) FROM subscription_opening_balances
                                WHERE user_id=? AND plan_id=? AND subscription_id<?),0)
                         ORDER BY o.paid_at,o.id",
                        [$balance['user_id'], $balance['plan_id'], $balance['last_order_id'],
                         $balance['user_id'], $balance['plan_id'], $cursor]
                    );
                    $periods = self::intervals($balance, $orders);
                    if ($periods === null) {
                        $stats['review']++;
                    } else {
                        $stats['matched']++;
                        if ($apply) {
                            foreach ($periods as $orderId => $dates) {
                                $db->update('subscription_periods', $dates + ['subscription_id' => $cursor, 'status' => 'active'],
                                    "order_id=? AND status='legacy_review'", [$orderId]);
                            }
                            $db->update('subscription_opening_balances', ['reconstructed' => 1], 'subscription_id=?', [$cursor]);
                            $stats['written']++;
                        }
                    }
                    $this->pdo->commit();
                } catch (Throwable $e) {
                    $this->pdo->rollBack();
                    throw $e;
                }
            }
        } while (count($balances) === 100);
        return $stats;
    }
}

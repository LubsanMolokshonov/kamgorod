<?php

// Подключаемый класс; прямой HTTP-вызов запрещён.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__ && php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

/**
 * Финансовый учёт возвратов. Суммы берём только из ответа API магазина,
 * никогда из тела webhook. Не меняет цены, CRM, документы и доступ к продуктам.
 */
class PaymentRefundAccounting
{
    public function __construct(private PDO $pdo, private object $client)
    {
    }

    public function syncRefund(string $refundId, bool $apply = true): array
    {
        $refund = $this->client->getRefundInfo($refundId);
        if ($refund->getId() !== $refundId || $refund->getStatus() !== 'succeeded') {
            throw new RuntimeException('ЮKassa не подтвердила успешный возврат');
        }
        $result = $this->syncPayment($refund->getPaymentId(), $apply);
        if (self::kopecks($result['refunded_amount']) < self::kopecks($refund->getAmount()->getValue())) {
            throw new RuntimeException('Сумма возврата ещё не отражена в платеже ЮKassa');
        }
        return $result;
    }

    public function syncPayment(string $paymentId, bool $apply = true): array
    {
        $payment = $this->client->getPaymentInfo($paymentId);
        if ($payment->getId() !== $paymentId || $payment->getStatus() !== 'succeeded') {
            throw new RuntimeException('ЮKassa не подтвердила исходный платёж');
        }
        $refunded = $payment->getRefundedAmount();
        $currency = $payment->getAmount()->getCurrency();
        if ($currency !== 'RUB' || ($refunded && $refunded->getCurrency() !== $currency)) {
            throw new RuntimeException('Несовместимая валюта возврата');
        }
        $gross = self::kopecks($payment->getAmount()->getValue());
        $refund = $refunded ? self::kopecks($refunded->getValue()) : 0;
        if ($gross <= 0 || $refund > $gross) {
            throw new RuntimeException('Некорректная сумма возврата ЮKassa');
        }
        if ($apply && $refund > 0) {
            // GREATEST защищает от старого ответа API, завершившегося позже нового.
            // Повтор webhook и несколько возвратов не складывают один возврат дважды.
            $stmt = $this->pdo->prepare(
                'INSERT INTO yookassa_refund_totals
                    (payment_id, payment_amount, refunded_amount, currency, checked_at)
                 VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE
                    refunded_amount = GREATEST(refunded_amount, VALUES(refunded_amount)),
                    checked_at = CURRENT_TIMESTAMP'
            );
            $stmt->execute([$paymentId, self::rubles($gross), self::rubles($refund), $currency]);
        }
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM orders WHERE yookassa_payment_id = ?"
        );
        $stmt->execute([$paymentId]);
        $orders = (int)$stmt->fetchColumn();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM token_transactions WHERE payment_id = ? AND reason = 'purchase'"
        );
        $stmt->execute([$paymentId]);
        return [
            'payment_id' => $paymentId,
            'payment_amount' => self::rubles($gross),
            'refunded_amount' => self::rubles($refund),
            'full' => $refund === $gross,
            'linked' => $orders > 0 || (int)$stmt->fetchColumn() > 0,
        ];
    }

    /** Без $since сверяем всю историю; cron использует перекрывающееся окно. */
    public function reconcile(bool $apply = false, ?string $since = null): array
    {
        $filter = ['limit' => 100];
        if ($since !== null) {
            $filter['created_at.gte'] = $since;
        }
        $stats = ['refunds' => 0, 'payments' => 0, 'amount' => 0.0, 'unlinked' => 0, 'errors' => 0];
        $seen = [];
        $cursors = [];
        do {
            $page = $this->client->getRefunds($filter);
            foreach ($page->getItems() as $refund) {
                if ($refund->getStatus() !== 'succeeded') {
                    continue;
                }
                $stats['refunds']++;
                $paymentId = $refund->getPaymentId();
                if (isset($seen[$paymentId])) {
                    continue;
                }
                try {
                    $result = $this->syncPayment($paymentId, $apply);
                    if (self::kopecks($result['refunded_amount']) === 0) {
                        throw new RuntimeException('Успешный возврат отсутствует в сумме платежа');
                    }
                    $seen[$paymentId] = true;
                    $stats['payments']++;
                    $stats['amount'] += (float)$result['refunded_amount'];
                    $stats['unlinked'] += $result['linked'] ? 0 : 1;
                } catch (Throwable $e) {
                    $stats['errors']++;
                    error_log('Refund reconciliation: ' . $paymentId . ': ' . $e->getMessage());
                }
            }
            $cursor = $page->getNextCursor();
            if ($cursor) {
                if (isset($cursors[$cursor])) {
                    throw new RuntimeException('ЮKassa повторила курсор списка возвратов');
                }
                $cursors[$cursor] = true;
                $filter['cursor'] = $cursor;
            }
        } while ($cursor);
        $stats['amount'] = round($stats['amount'], 2);
        return $stats;
    }

    private static function kopecks(string $amount): int
    {
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/D', $amount)) {
            throw new InvalidArgumentException('Некорректная денежная сумма');
        }
        [$rubles, $kopecks] = array_pad(explode('.', $amount), 2, '0');
        return (int)$rubles * 100 + (int)str_pad($kopecks, 2, '0');
    }

    private static function rubles(int $kopecks): string
    {
        return intdiv($kopecks, 100) . '.' . str_pad((string)($kopecks % 100), 2, '0', STR_PAD_LEFT);
    }
}

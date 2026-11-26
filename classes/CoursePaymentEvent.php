<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class CoursePaymentEvent
{
    public static function enrollmentForOrder(?array $order): int
    {
        foreach ($order['items'] ?? [] as $item) {
            if (!empty($item['course_enrollment_id'])) {
                return (int)$item['course_enrollment_id'];
            }
        }
        return 0;
    }

    public static function attemptKey(object $payment): string
    {
        $key = $payment->getMetadata()?->toArray()['course_checkout_attempt'] ?? '';
        return is_string($key) && strlen($key) <= 64 ? $key : '';
    }

    public static function enrollmentForAttempt(PDO $pdo, string $key): int
    {
        if ($key === '') {
            return 0;
        }
        $attempt = (new Database($pdo))->queryOne(
            'SELECT enrollment_id FROM course_checkout_payments WHERE attempt_key = ?',
            [$key]
        );
        return (int)($attempt['enrollment_id'] ?? 0);
    }

    /** Восстановить привязку из ответа API, если процесс оборвался после создания платежа. */
    public static function attachVerified(PDO $pdo, int $enrollmentId, object $payment): bool
    {
        $metadata = $payment->getMetadata()?->toArray() ?? [];
        $db = new Database($pdo);
        $attempt = $db->queryOne(
            "SELECT a.attempt_key, o.id, o.user_id, o.order_number, o.final_amount
             FROM course_checkout_payments a JOIN orders o ON o.id = a.order_id
             WHERE a.enrollment_id = ? AND a.attempt_key IS NOT NULL
               AND (o.yookassa_payment_id IS NULL OR o.yookassa_payment_id = '')
               AND o.payment_status IN ('pending', 'processing')",
            [$enrollmentId]
        );
        if (!$attempt
            || !hash_equals($attempt['attempt_key'], self::attemptKey($payment))
            || (string)($metadata['order_id'] ?? '') !== (string)$attempt['id']
            || (string)($metadata['order_number'] ?? '') !== $attempt['order_number']
            || (string)($metadata['user_id'] ?? '') !== (string)$attempt['user_id']
            || (string)($metadata['course_enrollment_id'] ?? '') !== (string)$enrollmentId
            || !self::matchesAmount($attempt, $payment)) {
            return false;
        }

        $confirmation = $payment->getConfirmation();
        $url = $confirmation && method_exists($confirmation, 'getConfirmationUrl')
            ? $confirmation->getConfirmationUrl() : null;
        $pdo->beginTransaction();
        try {
            $attached = $db->execute(
                "UPDATE orders SET yookassa_payment_id = ?, yookassa_confirmation_url = ?
                 WHERE id = ? AND (yookassa_payment_id IS NULL OR yookassa_payment_id = '')
                   AND payment_status IN ('pending', 'processing')",
                [$payment->getId(), $url, $attempt['id']]
            ) > 0;
            if ($attached) {
                $db->execute(
                    'UPDATE course_checkout_payments SET attempt_payload = NULL WHERE enrollment_id = ? AND attempt_key = ?',
                    [$enrollmentId, $attempt['attempt_key']]
                );
            }
            $pdo->commit();
            return $attached;
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function matchesAmount(array $order, object $payment): bool
    {
        $amount = $payment->getAmount();
        return $amount && $amount->getCurrency() === 'RUB'
            && (string)$amount->getValue() === number_format((float)$order['final_amount'], 2, '.', '');
    }
}

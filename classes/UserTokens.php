<?php
/**
 * UserTokens — баланс токенов пользователя для генератора материалов ФОП.
 *
 * Атомарность: списание (charge) и начисление (credit) выполняются в транзакции
 * с SELECT ... FOR UPDATE на строке user_tokens, чтобы не было гонок при
 * параллельных запросах. Любое изменение баланса пишется в token_transactions
 * (append-only журнал) — для аудита и refund'ов.
 *
 * NotEnoughTokensException бросается, когда баланс меньше требуемой суммы;
 * вызывающий код обязан её ловить и показать пользователю «купите пакет».
 */

require_once __DIR__ . '/../includes/material-tracking.php'; // isUnlimitedMaterialUser()

class NotEnoughTokensException extends RuntimeException {}

class UserTokens
{
    private const SIGNUP_BONUS_TOKENS = 30;

    private $db;
    private $pdo;

    /** Размер стартового бонуса — единый источник правды для UI и логики. */
    public static function signupBonus(): int
    {
        return self::SIGNUP_BONUS_TOKENS;
    }

    public function __construct($pdo)
    {
        $this->pdo = $pdo;
        $this->db = new Database($pdo);
    }

    public function getBalance(int $userId): int
    {
        $row = $this->db->queryOne(
            "SELECT balance FROM user_tokens WHERE user_id = ?",
            [$userId]
        );
        return (int)($row['balance'] ?? 0);
    }

    public function getRecord(int $userId): array
    {
        $row = $this->db->queryOne(
            "SELECT user_id, balance, lifetime_earned, lifetime_spent, updated_at
             FROM user_tokens WHERE user_id = ?",
            [$userId]
        );
        return $row ?: [
            'user_id' => $userId,
            'balance' => 0,
            'lifetime_earned' => 0,
            'lifetime_spent' => 0,
            'updated_at' => null,
        ];
    }

    /**
     * Списать токены. Бросает NotEnoughTokensException, если баланса не хватает.
     * Возвращает id записи в token_transactions.
     */
    public function charge(int $userId, int $amount, string $reason, array $meta = []): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('charge amount must be positive');
        }
        $this->validateReason($reason, ['generation', 'adaptation', 'download', 'admin_deduct']);

        // Белый список: без ограничений по токенам — не списываем (баланс остаётся
        // прежним) и не бросаем NotEnoughTokensException. Пишем нулевую транзакцию
        // для аудита факта генерации; refund() для таких пользователей тоже no-op.
        if (isUnlimitedMaterialUser($this->pdo, $userId)) {
            $this->db->beginTransaction();
            try {
                $this->ensureRow($userId);
                $meta['notes'] = trim(($meta['notes'] ?? '') . ' [unlimited: без списания]');
                $txnId = $this->logTransaction($userId, 0, $reason, $meta);
                $this->db->commit();
                return $txnId;
            } catch (Throwable $e) {
                if ($this->pdo->inTransaction()) {
                    $this->db->rollback();
                }
                throw $e;
            }
        }

        $this->db->beginTransaction();
        try {
            $this->ensureRow($userId);

            $stmt = $this->pdo->prepare(
                "SELECT balance FROM user_tokens WHERE user_id = ? FOR UPDATE"
            );
            $stmt->execute([$userId]);
            $current = (int)$stmt->fetchColumn();

            if ($current < $amount) {
                $this->db->rollback();
                throw new NotEnoughTokensException(
                    "Недостаточно токенов: на счёте {$current}, требуется {$amount}"
                );
            }

            $this->db->execute(
                "UPDATE user_tokens
                    SET balance = balance - ?, lifetime_spent = lifetime_spent + ?
                  WHERE user_id = ?",
                [$amount, $amount, $userId]
            );

            $txnId = $this->logTransaction($userId, -$amount, $reason, $meta);

            $this->db->commit();
            return $txnId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Начислить токены (покупка, бонус, refund). Возвращает id транзакции.
     */
    public function credit(int $userId, int $amount, string $reason, array $meta = []): int
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('credit amount must be positive');
        }
        $this->validateReason($reason, ['signup_bonus', 'purchase', 'refund', 'admin_grant', 'subscription']);

        $this->db->beginTransaction();
        try {
            $this->ensureRow($userId);

            $this->db->execute(
                "UPDATE user_tokens
                    SET balance = balance + ?, lifetime_earned = lifetime_earned + ?
                  WHERE user_id = ?",
                [$amount, $amount, $userId]
            );

            $txnId = $this->logTransaction($userId, $amount, $reason, $meta);

            $this->db->commit();
            return $txnId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->db->rollback();
            }
            throw $e;
        }
    }

    /**
     * Возврат токенов после неудачной генерации. Привязывается к id транзакции
     * списания (notes), чтобы можно было сопоставить пары charge/refund.
     */
    public function refund(int $userId, int $amount, int $originalTxnId, array $meta = []): int
    {
        // Белый список: списания не было (charge — нулевая транзакция), поэтому и
        // возвращать нечего — иначе баланс бы рос на каждой неудачной генерации.
        if (isUnlimitedMaterialUser($this->pdo, $userId)) {
            return 0;
        }
        $meta['notes'] = ($meta['notes'] ?? '') . " refund_of_txn={$originalTxnId}";
        return $this->credit($userId, $amount, 'refund', $meta);
    }

    /**
     * Стартовый бонус при первой загрузке генератора. Идемпотентно —
     * повторный вызов не начисляет повторно.
     */
    public function grantSignupBonusIfNeeded(int $userId): bool
    {
        $already = $this->db->queryOne(
            "SELECT id FROM token_transactions
              WHERE user_id = ? AND reason = 'signup_bonus' LIMIT 1",
            [$userId]
        );
        if ($already) {
            return false;
        }
        $this->credit($userId, self::SIGNUP_BONUS_TOKENS, 'signup_bonus');

        // Запланировать onboarding-цепочку (best-effort — не ломаем выдачу бонуса)
        try {
            require_once __DIR__ . '/MaterialTokenEmailChain.php';
            (new MaterialTokenEmailChain($this->pdo))->scheduleOnboarding($userId);
        } catch (Throwable $e) {
            error_log('grantSignupBonusIfNeeded: scheduleOnboarding failed: ' . $e->getMessage());
        }

        return true;
    }

    public function getHistory(int $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->db->query(
            "SELECT * FROM token_transactions
              WHERE user_id = ?
              ORDER BY created_at DESC, id DESC
              LIMIT ? OFFSET ?",
            [$userId, $limit, $offset]
        );
    }

    private function ensureRow(int $userId): void
    {
        $this->db->execute(
            "INSERT IGNORE INTO user_tokens (user_id, balance, lifetime_earned, lifetime_spent)
             VALUES (?, 0, 0, 0)",
            [$userId]
        );
    }

    private function logTransaction(int $userId, int $delta, string $reason, array $meta): int
    {
        return $this->db->insert('token_transactions', [
            'user_id' => $userId,
            'delta' => $delta,
            'reason' => $reason,
            'material_id' => $meta['material_id'] ?? null,
            'generation_id' => $meta['generation_id'] ?? null,
            'payment_id' => $meta['payment_id'] ?? null,
            'package_id' => $meta['package_id'] ?? null,
            'notes' => $meta['notes'] ?? null,
            // Сумма оплаты и UTM-атрибуция (миграция 140) — для выручки ФОП в РНП.
            'amount_paid' => $meta['amount_paid'] ?? null,
            'utm_source'   => $meta['utm_source']   ?? null,
            'utm_medium'   => $meta['utm_medium']   ?? null,
            'utm_campaign' => $meta['utm_campaign'] ?? null,
            'utm_content'  => $meta['utm_content']  ?? null,
            'utm_term'     => $meta['utm_term']     ?? null,
        ]);
    }

    private function validateReason(string $reason, array $allowed): void
    {
        if (!in_array($reason, $allowed, true)) {
            throw new InvalidArgumentException("Недопустимый reason='{$reason}'");
        }
    }
}

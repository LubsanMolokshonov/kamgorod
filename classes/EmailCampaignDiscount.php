<?php
/**
 * EmailCampaignDiscount — скидки по разовым email-кампаниям.
 *
 * Модель хранит по строке на пользователя в таблице email_campaign_discounts:
 *   - user_id, campaign_code, rate (0.10 = 10%), expires_at, used_in_order_id
 * Активной считается запись с expires_at > NOW() и используется ровно один раз
 * (после успешной оплаты — markUsed() проставляет used_in_order_id/used_at).
 *
 * Приоритет относительно остальных скидок — см. ajax/create-payment.php и
 * ajax/create-course-payment.php: campaign-скидка применяется только если
 * loyalty (25% корзина / 10% курсы) не сработала и нет email-токена курса.
 */
class EmailCampaignDiscount {
    /**
     * Получить активную (не использованную, не просроченную) скидку пользователя.
     *
     * @return array|null ['id','rate','expires_at','campaign_code'] или null
     */
    public static function getActive(PDO $pdo, ?int $userId): ?array {
        if (!$userId) {
            return null;
        }
        try {
            $stmt = $pdo->prepare(
                "SELECT id, campaign_code, rate, expires_at
                   FROM email_campaign_discounts
                  WHERE user_id = ?
                    AND used_in_order_id IS NULL
                    AND expires_at > NOW()
                  ORDER BY rate DESC, expires_at ASC
                  LIMIT 1"
            );
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Быстрая проверка: есть ли активная скидка у пользователя (для UI в корзине).
     */
    public static function getActiveRate(PDO $pdo, ?int $userId): float {
        $row = self::getActive($pdo, $userId);
        return $row ? (float)$row['rate'] : 0.0;
    }

    /**
     * Рассчитать скидку с округлением.
     *
     * @return array ['rate'=>float, 'amount'=>float, 'final'=>float]
     */
    public static function calculate(float $amount, float $rate): array {
        if ($amount <= 0 || $rate <= 0) {
            return ['rate' => $rate, 'amount' => 0.0, 'final' => max(0.0, $amount)];
        }
        $discount = round($amount * $rate, 2);
        $final = round($amount - $discount, 2);
        return ['rate' => $rate, 'amount' => $discount, 'final' => $final];
    }

    /**
     * Пометить скидку использованной (вызывать из webhook'а после успешной оплаты).
     */
    public static function markUsed(PDO $pdo, int $userId, int $orderId): bool {
        try {
            $stmt = $pdo->prepare(
                "UPDATE email_campaign_discounts
                    SET used_in_order_id = ?, used_at = NOW()
                  WHERE user_id = ?
                    AND used_in_order_id IS NULL
                    AND expires_at > NOW()
                  ORDER BY id ASC
                  LIMIT 1"
            );
            $stmt->execute([$orderId, $userId]);
            return $stmt->rowCount() > 0;
        } catch (\Exception $e) {
            error_log('EmailCampaignDiscount::markUsed failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Создать/обновить скидку для пользователя (используется при планировании кампании).
     */
    public static function upsert(PDO $pdo, string $campaignCode, int $userId, string $email, float $rate, string $expiresAt): void {
        $stmt = $pdo->prepare(
            "INSERT INTO email_campaign_discounts (campaign_code, user_id, email, rate, expires_at)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
               email = VALUES(email),
               rate = VALUES(rate),
               expires_at = VALUES(expires_at)"
        );
        $stmt->execute([$campaignCode, $userId, $email, $rate, $expiresAt]);
    }
}

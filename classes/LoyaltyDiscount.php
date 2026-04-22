<?php
/**
 * LoyaltyDiscount — пожизненная скидка лояльности.
 *
 * После первого успешного платежа пользователю выставляется флаг
 * users.has_lifetime_discount = 1. После этого:
 *  - к корзине (конкурсы/олимпиады/вебинары/публикации) применяется RATE_CART (25%)
 *    поверх акции 2+1 (сначала 2+1, потом процент от остатка);
 *  - к оплате курсов КПК/ПП применяется RATE_COURSE (10%).
 */
class LoyaltyDiscount {
    /** Ставка скидки на корзину */
    const RATE_CART = 0.25;

    /** Ставка скидки на курсы */
    const RATE_COURSE = 0.10;

    /**
     * Имеет ли пользователь активный статус пожизненной скидки.
     */
    public static function isEligible(PDO $pdo, ?int $userId): bool {
        if (!$userId) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT has_lifetime_discount FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return !empty($row) && (int)$row['has_lifetime_discount'] === 1;
        } catch (\Exception $e) {
            // Колонка может отсутствовать до применения миграции 085.
            return false;
        }
    }

    /**
     * Является ли $currentOrderId первым успешно оплаченным заказом пользователя.
     * Исключает текущий заказ из подсчёта, чтобы проверка работала после
     * обновления статуса текущего заказа на 'succeeded'.
     */
    public static function isFirstSuccessfulOrder(PDO $pdo, int $userId, int $currentOrderId): bool {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS cnt FROM orders
             WHERE user_id = ? AND payment_status = 'succeeded' AND id != ?"
        );
        $stmt->execute([$userId, $currentOrderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['cnt'] ?? 0) === 0;
    }

    /**
     * Рассчитать loyalty-скидку для корзины (поверх акции 2+1).
     *
     * @param float $amountAfterPromotion сумма после применения 2+1
     * @return array ['rate' => float, 'amount' => float, 'final' => float]
     */
    public static function calculateCartDiscount(float $amountAfterPromotion): array {
        if ($amountAfterPromotion <= 0) {
            return ['rate' => self::RATE_CART, 'amount' => 0.0, 'final' => 0.0];
        }
        $amount = round($amountAfterPromotion * self::RATE_CART, 2);
        $final = round($amountAfterPromotion - $amount, 2);
        return ['rate' => self::RATE_CART, 'amount' => $amount, 'final' => $final];
    }

    /**
     * Рассчитать loyalty-скидку для курса.
     *
     * @param float $price базовая цена
     * @return array ['rate' => float, 'amount' => float, 'final' => float]
     */
    public static function calculateCourseDiscount(float $price): array {
        if ($price <= 0) {
            return ['rate' => self::RATE_COURSE, 'amount' => 0.0, 'final' => 0.0];
        }
        $amount = round($price * self::RATE_COURSE, 2);
        $final = round($price - $amount, 2);
        return ['rate' => self::RATE_COURSE, 'amount' => $amount, 'final' => $final];
    }

    /**
     * Пропорционально распределить общую скидку по ценам позиций (для чека 54-ФЗ).
     * Гарантирует, что сумма скорректированных цен === $total - $discount
     * (остаток копеек отдаётся последней ненулевой позиции).
     *
     * @param float[] $prices список цен позиций (только оплачиваемых, не «бесплатно по 2+1»)
     * @param float $totalDiscount общая сумма скидки к распределению
     * @return float[] новые цены позиций в том же порядке
     */
    public static function distributePricesWithDiscount(array $prices, float $totalDiscount): array {
        $subtotal = 0.0;
        foreach ($prices as $p) {
            $subtotal += (float)$p;
        }
        if ($subtotal <= 0 || $totalDiscount <= 0) {
            return array_map(fn($p) => round((float)$p, 2), $prices);
        }

        $targetTotal = round($subtotal - $totalDiscount, 2);
        if ($targetTotal < 0) {
            $targetTotal = 0.0;
        }

        $adjusted = [];
        $accum = 0.0;
        $lastIdx = null;
        foreach ($prices as $i => $p) {
            $share = round(((float)$p / $subtotal) * $targetTotal, 2);
            $adjusted[$i] = $share;
            $accum += $share;
            if ($share > 0) {
                $lastIdx = $i;
            }
        }

        // Остаток копеек отдаём последней ненулевой позиции, чтобы сумма сошлась.
        $remainder = round($targetTotal - $accum, 2);
        if (abs($remainder) >= 0.01 && $lastIdx !== null) {
            $adjusted[$lastIdx] = round($adjusted[$lastIdx] + $remainder, 2);
            if ($adjusted[$lastIdx] < 0) {
                $adjusted[$lastIdx] = 0.0;
            }
        }

        return $adjusted;
    }
}

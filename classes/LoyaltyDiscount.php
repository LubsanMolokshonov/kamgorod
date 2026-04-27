<?php
/**
 * LoyaltyDiscount — пожизненная скидка лояльности.
 *
 * После первого успешного платежа пользователю выставляется флаг
 * users.has_lifetime_discount = 1. После этого:
 *  - к корзине (конкурсы/олимпиады/вебинары/публикации) применяется RATE_CART (25%)
 *    поверх акции 2+1 (сначала 2+1, потом процент от остатка);
 *  - к оплате курсов КПК/ПП применяется RATE_COURSE (10%).
 *
 * Индивидуальные ставки (миграция 092): если у пользователя заполнены
 * individual_cart_discount / individual_course_discount — они перекрывают стандартные.
 */
class LoyaltyDiscount {
    /** Ставка скидки на корзину (стандарт) */
    const RATE_CART = 0.25;

    /** Ставка скидки на курсы (стандарт) */
    const RATE_COURSE = 0.10;

    /**
     * Имеет ли пользователь активный статус пожизненной скидки.
     * Также возвращает true, если у пользователя заданы индивидуальные ставки.
     */
    public static function isEligible(PDO $pdo, ?int $userId): bool {
        if (!$userId) {
            return false;
        }
        try {
            $stmt = $pdo->prepare("SELECT has_lifetime_discount, individual_cart_discount FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (empty($row)) return false;
            return (int)$row['has_lifetime_discount'] === 1 || $row['individual_cart_discount'] !== null;
        } catch (\Exception $e) {
            // Колонки могут отсутствовать до применения миграции.
            return false;
        }
    }

    /**
     * Возвращает эффективные ставки скидки для пользователя.
     * Если заданы индивидуальные ставки — они используются вместо стандартных.
     *
     * @return array ['cart' => float, 'course' => float]
     */
    public static function getEffectiveRates(PDO $pdo, int $userId): array {
        try {
            $stmt = $pdo->prepare("SELECT individual_cart_discount, individual_course_discount FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return [
                'cart'   => $row && $row['individual_cart_discount'] !== null
                    ? (float)$row['individual_cart_discount']
                    : self::RATE_CART,
                'course' => $row && $row['individual_course_discount'] !== null
                    ? (float)$row['individual_course_discount']
                    : self::RATE_COURSE,
            ];
        } catch (\Exception $e) {
            return ['cart' => self::RATE_CART, 'course' => self::RATE_COURSE];
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
     * @param float      $amountAfterPromotion сумма после применения 2+1
     * @param float|null $rate                 ставка (null = RATE_CART)
     * @return array ['rate' => float, 'amount' => float, 'final' => float]
     */
    public static function calculateCartDiscount(float $amountAfterPromotion, ?float $rate = null): array {
        $rate = $rate ?? self::RATE_CART;
        if ($amountAfterPromotion <= 0) {
            return ['rate' => $rate, 'amount' => 0.0, 'final' => 0.0];
        }
        $amount = round($amountAfterPromotion * $rate, 2);
        $final = round($amountAfterPromotion - $amount, 2);
        return ['rate' => $rate, 'amount' => $amount, 'final' => $final];
    }

    /**
     * Рассчитать loyalty-скидку для курса.
     *
     * @param float      $price базовая цена
     * @param float|null $rate  ставка (null = RATE_COURSE)
     * @return array ['rate' => float, 'amount' => float, 'final' => float]
     */
    public static function calculateCourseDiscount(float $price, ?float $rate = null): array {
        $rate = $rate ?? self::RATE_COURSE;
        if ($price <= 0) {
            return ['rate' => $rate, 'amount' => 0.0, 'final' => 0.0];
        }
        $amount = round($price * $rate, 2);
        $final = round($price - $amount, 2);
        return ['rate' => $rate, 'amount' => $amount, 'final' => $final];
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

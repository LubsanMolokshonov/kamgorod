<?php
/**
 * Ценообразование курсов
 *
 * Режим 1 (текущий): Фиксированная скидка COURSE_FIXED_DISCOUNT% на все курсы.
 * Режим 2 (отключён): A/B-тест цен через подписанную cookie.
 *
 * Варианты: A (100%), B (50%), C (30%), D (фиксированная скидка).
 */
class CoursePriceAB
{
    private const VARIANTS = ['A', 'B', 'C', 'D'];
    private const MULTIPLIERS = [
        'A' => 1.0,
        'B' => 0.5,
        'C' => 0.3,
    ];
    private const COOKIE_TTL = 30 * 24 * 3600; // 30 дней

    /**
     * Получить вариант текущего пользователя.
     * При фиксированной скидке всегда возвращает 'D'.
     * При активном A/B-тесте читает cookie; если нет — назначает случайно.
     */
    public static function getVariant(): string
    {
        // Фиксированная скидка — всегда вариант D
        if (self::hasFixedDiscount()) {
            return 'D';
        }

        if (!defined('COURSE_AB_TEST_ACTIVE') || !COURSE_AB_TEST_ACTIVE) {
            return 'A';
        }

        $cookieName = defined('COURSE_AB_TEST_COOKIE') ? COURSE_AB_TEST_COOKIE : 'cab_v';

        // Попробовать прочитать из cookie
        if (!empty($_COOKIE[$cookieName])) {
            $variant = self::verify($_COOKIE[$cookieName]);
            if ($variant !== null) {
                return $variant;
            }
        }

        // Назначить случайно
        $abVariants = ['A', 'B', 'C'];
        $variant = $abVariants[random_int(0, count($abVariants) - 1)];

        // Установить cookie
        $signed = self::sign($variant);
        setcookie($cookieName, $signed, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'httponly'  => true,
            'samesite'  => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        ]);

        // Сделать доступным в текущем запросе
        $_COOKIE[$cookieName] = $signed;

        return $variant;
    }

    /**
     * Фиксированная скидка активна?
     */
    public static function hasFixedDiscount(): bool
    {
        return defined('COURSE_FIXED_DISCOUNT') && COURSE_FIXED_DISCOUNT > 0;
    }

    /**
     * A/B-тест активен?
     */
    public static function isActive(): bool
    {
        return self::hasFixedDiscount() || (defined('COURSE_AB_TEST_ACTIVE') && COURSE_AB_TEST_ACTIVE);
    }

    /**
     * Применить множитель варианта к базовой цене.
     */
    public static function getAdjustedPrice(float $basePrice, string $variant, ?string $programType = null): float
    {
        if ($variant === 'D' || self::hasFixedDiscount()) {
            $discount = self::fixedDiscountFor($programType);
            return round($basePrice * (1 - $discount / 100));
        }

        $multiplier = self::MULTIPLIERS[$variant] ?? 1.0;
        return round($basePrice * $multiplier);
    }

    /**
     * Получить процент скидки для варианта (для отображения).
     */
    public static function getDiscountPercent(string $variant, ?string $programType = null): int
    {
        if ($variant === 'D' || self::hasFixedDiscount()) {
            return (int) self::fixedDiscountFor($programType);
        }

        return match ($variant) {
            'B' => 50,
            'C' => 70,
            default => 0,
        };
    }

    /**
     * Размер фиксированной скидки в % с учётом типа программы.
     * KPK — повышение квалификации, PP — переподготовка.
     */
    private static function fixedDiscountFor(?string $programType): int
    {
        if ($programType === 'pp' && defined('COURSE_FIXED_DISCOUNT_PP')) {
            return (int) COURSE_FIXED_DISCOUNT_PP;
        }
        if ($programType === 'kpk' && defined('COURSE_FIXED_DISCOUNT_KPK')) {
            return (int) COURSE_FIXED_DISCOUNT_KPK;
        }
        return defined('COURSE_FIXED_DISCOUNT') ? (int) COURSE_FIXED_DISCOUNT : 0;
    }

    /**
     * Подписать вариант HMAC-SHA256.
     */
    private static function sign(string $variant): string
    {
        $secret = defined('COURSE_AB_TEST_SECRET') ? COURSE_AB_TEST_SECRET : '';
        $hmac = hash_hmac('sha256', $variant, $secret);
        return $variant . '.' . $hmac;
    }

    /**
     * Проверить подпись cookie. Возвращает вариант или null при ошибке.
     */
    private static function verify(string $cookieValue): ?string
    {
        $parts = explode('.', $cookieValue, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$variant, $hmac] = $parts;

        if (!in_array($variant, self::VARIANTS, true)) {
            return null;
        }

        $secret = defined('COURSE_AB_TEST_SECRET') ? COURSE_AB_TEST_SECRET : '';
        $expected = hash_hmac('sha256', $variant, $secret);

        if (!hash_equals($expected, $hmac)) {
            return null;
        }

        return $variant;
    }
}

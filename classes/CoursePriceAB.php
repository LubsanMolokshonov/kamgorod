<?php
/**
 * A/B-тест цен курсов
 *
 * Серверное назначение варианта через подписанную cookie.
 * Варианты: A (100%), B (50%), C (30% от базовой цены).
 */
class CoursePriceAB
{
    private const VARIANTS = ['A', 'B', 'C'];
    private const MULTIPLIERS = [
        'A' => 1.0,
        'B' => 0.5,
        'C' => 0.3,
    ];
    private const COOKIE_TTL = 30 * 24 * 3600; // 30 дней

    /**
     * Получить вариант текущего пользователя.
     * Читает подписанную cookie; если нет — назначает случайно и ставит cookie.
     */
    public static function getVariant(): string
    {
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
        $variant = self::VARIANTS[random_int(0, count(self::VARIANTS) - 1)];

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
     * Тест активен?
     */
    public static function isActive(): bool
    {
        return defined('COURSE_AB_TEST_ACTIVE') && COURSE_AB_TEST_ACTIVE;
    }

    /**
     * Применить множитель варианта к базовой цене.
     */
    public static function getAdjustedPrice(float $basePrice, string $variant): float
    {
        $multiplier = self::MULTIPLIERS[$variant] ?? 1.0;
        return round($basePrice * $multiplier);
    }

    /**
     * Получить процент скидки для варианта (для отображения).
     */
    public static function getDiscountPercent(string $variant): int
    {
        return match ($variant) {
            'B' => 50,
            'C' => 70,
            default => 0,
        };
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

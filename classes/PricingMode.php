<?php
/**
 * A/B-тест моделей оплаты на всём трафике (50/50).
 *
 *   Вариант A ('A') — control: текущая поштучная оплата дипломов/сертификатов/токенов.
 *   Вариант B ('B') — subscription: документы и пакеты токенов можно получить ТОЛЬКО
 *                      по подписке; поштучная оплата заблокирована.
 *
 * Назначение стабильно за человеком:
 *   - аноним — подписанная HMAC-cookie pm_v (как CoursePriceAB);
 *   - залогиненный — авторитетна колонка users.pricing_variant (фиксируется при первом
 *     логине), cookie синхронизируется. Так человек не «прыгает» между моделями и держит
 *     вариант между устройствами.
 *
 * Kill-switch: PRICING_AB_ACTIVE=false → все в 'A' (текущий сайт без изменений).
 *
 * Класс самодостаточен (не тянет SubscriptionService/material-tracking) — его зовут и
 * страницы, и AJAX-эндпоинты, и админка.
 */
class PricingMode
{
    public const CONTROL      = 'A';
    public const SUBSCRIPTION  = 'B';
    private const VARIANTS     = ['A', 'B'];
    private const COOKIE_TTL    = 180 * 24 * 3600; // 180 дней

    /** Request-scoped кэш разрешённого варианта. */
    private static ?string $cache = null;

    /**
     * Эксперимент активен?
     */
    public static function isActive(): bool
    {
        return defined('PRICING_AB_ACTIVE') && PRICING_AB_ACTIVE;
    }

    /**
     * Текущий вариант посетителя: 'A' или 'B'.
     */
    public static function getVariant(): string
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        // Эксперимент выключен → все в control. Cookie/колонку не трогаем.
        if (!self::isActive()) {
            return self::$cache = self::CONTROL;
        }

        $cookieName = defined('PRICING_AB_COOKIE') ? PRICING_AB_COOKIE : 'pm_v';
        $userId     = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;

        // 1) Залогинен и вариант уже зафиксирован за аккаунтом — он авторитетен.
        if ($userId > 0) {
            $stored = self::readUserVariant($userId);
            if ($stored !== null) {
                self::setCookie($cookieName, $stored); // синхронизируем cookie с БД
                return self::$cache = $stored;
            }
        }

        // 2) Иначе валидная cookie.
        $variant = null;
        if (!empty($_COOKIE[$cookieName])) {
            $variant = self::verify($_COOKIE[$cookieName]);
        }

        // 3) Иначе — случайное назначение 50/50.
        if ($variant === null) {
            $variant = self::VARIANTS[random_int(0, 1)];
            self::setCookie($cookieName, $variant);
        }

        // 4) Залогинен, но колонка пуста — фиксируем вариант за аккаунтом.
        if ($userId > 0) {
            self::writeUserVariant($userId, $variant);
        }

        return self::$cache = $variant;
    }

    /**
     * Вариант B и эксперимент активен — поштучная оплата документов/токенов запрещена.
     */
    public static function isSubscriptionOnly(): bool
    {
        return self::isActive() && self::getVariant() === self::SUBSCRIPTION;
    }

    public static function isControl(): bool
    {
        return self::getVariant() === self::CONTROL;
    }

    /**
     * Семантическая метка для Яндекс.Метрики/отчётов.
     */
    public static function label(): string
    {
        return self::getVariant() === self::SUBSCRIPTION ? 'subscription' : 'control';
    }

    /**
     * Проставить вариант на заказе (для атрибуции выручки по модели).
     * $db — PDO. Безопасно при отсутствии колонки (заглушается).
     */
    public static function stampOrder($db, int $orderId): void
    {
        if ($orderId <= 0) {
            return;
        }
        try {
            $stmt = $db->prepare("UPDATE orders SET pricing_variant = ? WHERE id = ?");
            $stmt->execute([self::getVariant(), $orderId]);
        } catch (Throwable $e) {
            error_log('PricingMode::stampOrder failed: ' . $e->getMessage());
        }
    }

    // ── внутреннее ──────────────────────────────────────────────────────────

    private static function readUserVariant(int $userId): ?string
    {
        try {
            $pdo = $GLOBALS['db'] ?? null;
            if (!$pdo instanceof PDO) {
                return null;
            }
            $stmt = $pdo->prepare("SELECT pricing_variant FROM users WHERE id = ? LIMIT 1");
            $stmt->execute([$userId]);
            $v = $stmt->fetchColumn();
            return ($v !== false && in_array($v, self::VARIANTS, true)) ? $v : null;
        } catch (Throwable $e) {
            return null;
        }
    }

    private static function writeUserVariant(int $userId, string $variant): void
    {
        try {
            $pdo = $GLOBALS['db'] ?? null;
            if (!$pdo instanceof PDO) {
                return;
            }
            // Только если колонка ещё пуста — не перетираем зафиксированный вариант.
            $stmt = $pdo->prepare("UPDATE users SET pricing_variant = ? WHERE id = ? AND pricing_variant IS NULL");
            $stmt->execute([$variant, $userId]);
        } catch (Throwable $e) {
            error_log('PricingMode::writeUserVariant failed: ' . $e->getMessage());
        }
    }

    private static function setCookie(string $cookieName, string $variant): void
    {
        $signed = self::sign($variant);
        // Доступно в текущем запросе сразу.
        $_COOKIE[$cookieName] = $signed;
        if (headers_sent()) {
            return;
        }
        setcookie($cookieName, $signed, [
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
        ]);
    }

    private static function sign(string $variant): string
    {
        $secret = defined('PRICING_AB_SECRET') ? PRICING_AB_SECRET : '';
        return $variant . '.' . hash_hmac('sha256', $variant, $secret);
    }

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
        $secret   = defined('PRICING_AB_SECRET') ? PRICING_AB_SECRET : '';
        $expected = hash_hmac('sha256', $variant, $secret);
        return hash_equals($expected, $hmac) ? $variant : null;
    }
}

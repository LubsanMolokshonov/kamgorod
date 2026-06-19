<?php
/**
 * Трекинг воронки «Материалы ФОП»: единая точка логирования визитов на всех точках
 * входа рекламы, сквозной funnel_session_id (аноним → регистрация → оплата) и захват
 * UTM в сессию для последующей атрибуции оплат на кампанию.
 *
 * Вызывать в начале страниц-входов (до вывода HTML — функции ставят cookie):
 *   require_once __DIR__ . '/../includes/material-tracking.php';
 *   trackMaterialVisit($db, '/material-generator/');
 *
 * Требует, чтобы session_start() уже был вызван.
 */

if (!function_exists('materialFunnelSessionId')) {
    /**
     * Стабильный id анонимной воронки. Живёт в cookie 90 дней, переживает регистрацию.
     */
    function materialFunnelSessionId(): string
    {
        if (!empty($_COOKIE['mat_fsid'])) {
            $fsid = preg_replace('/[^a-f0-9]/', '', (string)$_COOKIE['mat_fsid']);
            if (strlen($fsid) === 32) {
                return $fsid;
            }
        }
        $fsid = bin2hex(random_bytes(16));
        if (!headers_sent()) {
            setcookie('mat_fsid', $fsid, [
                'expires'  => time() + 90 * 24 * 3600,
                'path'     => '/',
                'samesite' => 'Lax',
            ]);
        }
        $_COOKIE['mat_fsid'] = $fsid;
        return $fsid;
    }
}

if (!function_exists('captureMaterialUtm')) {
    /**
     * Захватываем UTM из первого визита и держим в сессии. Не перезаписываем —
     * атрибуция остаётся за первым касанием воронки.
     */
    function captureMaterialUtm(): void
    {
        foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'] as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '' && empty($_SESSION['mat_' . $k])) {
                $_SESSION['mat_' . $k] = mb_substr((string)$_GET[$k], 0, 150);
            }
        }
    }
}

if (!function_exists('trackMaterialVisit')) {
    /**
     * Логирует уникальный (по PHP-сессии) визит на точку входа воронки материалов.
     * Best-effort: ошибки не ломают страницу.
     */
    function trackMaterialVisit(PDO $pdo, string $entryPath): void
    {
        try {
            captureMaterialUtm();
            $fsid = materialFunnelSessionId();
            $sid  = session_id();
            if (!$sid) {
                return;
            }
            $stmt = $pdo->prepare(
                "INSERT IGNORE INTO material_landing_visits
                 (php_session_id, funnel_session_id, user_id, ip_address, user_agent, referrer,
                  entry_path, utm_source, utm_medium, utm_campaign, utm_content)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                $sid,
                $fsid,
                $_SESSION['user_id'] ?? null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500) ?: null,
                substr($_SERVER['HTTP_REFERER'] ?? '', 0, 500) ?: null,
                mb_substr($entryPath, 0, 255),
                $_SESSION['mat_utm_source']   ?? null,
                $_SESSION['mat_utm_medium']   ?? null,
                $_SESSION['mat_utm_campaign'] ?? null,
                $_SESSION['mat_utm_content']  ?? null,
            ]);
        } catch (\Throwable $e) {
            error_log('trackMaterialVisit: ' . $e->getMessage());
        }
    }
}

if (!function_exists('claimAnonymousMaterials')) {
    /**
     * Привязывает анонимные превью-материалы и логи генераций к пользователю после
     * регистрации/входа (по funnel_session_id из cookie). Best-effort.
     */
    function claimAnonymousMaterials(PDO $pdo, int $userId): void
    {
        $fsid = $_COOKIE['mat_fsid'] ?? '';
        if (strlen($fsid) !== 32) {
            return;
        }
        try {
            $stmt = $pdo->prepare(
                "UPDATE materials SET user_id = ? WHERE funnel_session_id = ? AND user_id IS NULL"
            );
            $stmt->execute([$userId, $fsid]);
            $stmt = $pdo->prepare(
                "UPDATE material_generations SET user_id = ? WHERE funnel_session_id = ? AND user_id IS NULL"
            );
            $stmt->execute([$userId, $fsid]);
        } catch (\Throwable $e) {
            error_log('claimAnonymousMaterials: ' . $e->getMessage());
        }
    }
}

if (!function_exists('isUnlimitedMaterialUser')) {
    /**
     * Пользователь из белого списка MATERIAL_UNLIMITED_EMAILS: без суточного лимита
     * генераций и без списания токенов. Проверка по e-mail из таблицы users.
     * Результат кэшируется в рамках запроса (по user_id).
     */
    function isUnlimitedMaterialUser(PDO $pdo, ?int $userId): bool
    {
        static $cache = [];
        if ($userId === null) {
            return false;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        // Подписчик Про: безлимит генератора ФОП — те же семантики, что и whitelist
        // (без списания токенов, без суточного rate-limit). SubscriptionService
        // самодостаточен (UserTokens подключает лениво), цикла require нет.
        try {
            require_once __DIR__ . '/../classes/SubscriptionService.php';
            if ((new SubscriptionService($pdo))->hasUnlimitedGenerations($userId)) {
                return $cache[$userId] = true;
            }
        } catch (\Throwable $e) {
            error_log('isUnlimitedMaterialUser sub-check: ' . $e->getMessage());
        }
        $allowed = defined('MATERIAL_UNLIMITED_EMAILS') ? MATERIAL_UNLIMITED_EMAILS : [];
        if (empty($allowed)) {
            return $cache[$userId] = false;
        }
        try {
            $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $email = strtolower(trim((string)$stmt->fetchColumn()));
            return $cache[$userId] = ($email !== '' && in_array($email, $allowed, true));
        } catch (\Throwable $e) {
            error_log('isUnlimitedMaterialUser: ' . $e->getMessage());
            return $cache[$userId] = false;
        }
    }
}

if (!function_exists('materialDailyLimitBonus')) {
    /**
     * Персональная прибавка к суточному лимиту превью-генераций (поле
     * users.material_daily_limit_bonus). Кэшируется в рамках запроса.
     * Колонка может отсутствовать (до миграции 137) — тогда 0.
     */
    function materialDailyLimitBonus(PDO $pdo, ?int $userId): int
    {
        static $cache = [];
        if ($userId === null) {
            return 0;
        }
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        try {
            $stmt = $pdo->prepare("SELECT material_daily_limit_bonus FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            return $cache[$userId] = (int)$stmt->fetchColumn();
        } catch (\Throwable $e) {
            return $cache[$userId] = 0;
        }
    }
}

if (!function_exists('materialPreviewRateLimit')) {
    /**
     * Лимит на бесплатные превью-генерации за 24ч (защита от слива денег на ИИ).
     * Возвращает текст ошибки, если лимит превышен, иначе null.
     *
     * Анонимы: по funnel_session_id и по IP (на случай сброса cookie).
     * Залогиненные: по user_id (выше лимит — они уже в воронке).
     */
    function materialPreviewRateLimit(PDO $pdo, ?int $userId, ?string $funnelSessionId, ?string $ip): ?string
    {
        // Белый список: лимиты не применяем.
        if (isUnlimitedMaterialUser($pdo, $userId)) {
            return null;
        }

        $countSince = function (string $where, array $args) use ($pdo): int {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM material_generations
                  WHERE mode = 'preview' AND created_at > (NOW() - INTERVAL 1 DAY) AND " . $where
            );
            $stmt->execute($args);
            return (int)$stmt->fetchColumn();
        };

        if ($userId !== null) {
            // Базовый лимит + персональная прибавка (кнопка «Увеличить лимит»).
            $limit = 10 + materialDailyLimitBonus($pdo, $userId);
            if ($countSince('user_id = ?', [$userId]) >= $limit) {
                return 'Вы создали много материалов за сутки. Попробуйте завтра или напишите в поддержку.';
            }
            return null;
        }

        if ($funnelSessionId !== null && $countSince('funnel_session_id = ?', [$funnelSessionId]) >= 3) {
            return 'Лимит бесплатных генераций исчерпан. Зарегистрируйтесь — подарим токены и снимем ограничение.';
        }
        if ($ip !== null && $ip !== '' && $countSince('ip_address = ?', [$ip]) >= 8) {
            return 'Слишком много генераций с вашего адреса. Зарегистрируйтесь, чтобы продолжить.';
        }
        return null;
    }
}

if (!function_exists('persistMaterialUtmToUser')) {
    /**
     * Переносит атрибуцию привлечения на пользователя при регистрации.
     * COALESCE — пишем только если поле ещё пустое (первое касание побеждает).
     */
    function persistMaterialUtmToUser(PDO $pdo, int $userId): void
    {
        try {
            $stmt = $pdo->prepare(
                "UPDATE users
                    SET utm_source        = COALESCE(utm_source, ?),
                        utm_medium        = COALESCE(utm_medium, ?),
                        utm_campaign      = COALESCE(utm_campaign, ?),
                        funnel_session_id = COALESCE(funnel_session_id, ?)
                  WHERE id = ?"
            );
            $stmt->execute([
                $_SESSION['mat_utm_source']   ?? null,
                $_SESSION['mat_utm_medium']   ?? null,
                $_SESSION['mat_utm_campaign'] ?? null,
                materialFunnelSessionId(),
                $userId,
            ]);
        } catch (\Throwable $e) {
            error_log('persistMaterialUtmToUser: ' . $e->getMessage());
        }
    }
}

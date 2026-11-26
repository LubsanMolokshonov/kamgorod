<?php
/**
 * Magic Link Helper
 * Генерация и валидация HMAC-токенов для авто-авторизации из email-ссылок
 */

/**
 * Генерирует HMAC-токен для magic-ссылки
 *
 * @param int $userId ID пользователя
 * @param int $expiryDays Срок действия в днях (по умолчанию 7)
 * @return string base64url-encoded токен
 */
function generateMagicToken($userId, $expiryDays = 7) {
    $expiry = time() + ($expiryDays * 86400);
    $payload = $userId . ':' . $expiry;
    $hmac = hash_hmac('sha256', $payload, getMagicLinkSecret());

    return base64url_encode($payload . ':' . $hmac);
}

/**
 * Валидирует magic-токен
 *
 * @param string $token base64url-encoded токен
 * @return int|false ID пользователя или false при ошибке
 */
function validateMagicToken($token) {
    $decoded = base64url_decode($token);
    if ($decoded === false) {
        return false;
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 3) {
        return false;
    }

    list($userId, $expiry, $hmac) = $parts;

    // Проверяем HMAC-подпись
    $payload = $userId . ':' . $expiry;
    try {
        $expectedHmac = hash_hmac('sha256', $payload, getMagicLinkSecret());
    } catch (RuntimeException $e) {
        return false;
    }

    if (!hash_equals($expectedHmac, $hmac)) {
        return false;
    }

    // Проверяем срок действия
    if (time() > (int)$expiry) {
        return false;
    }

    return (int)$userId;
}

/**
 * Новый формат сохраняет URL писем и не пишет в БД при предварительном просмотре рассылки.
 * Случайный nonce делает каждое письмо независимым; расход фиксируется только подтверждением.
 */
function issueEmailMagicToken(int $userId, int $expiryDays): string {
    $payload = $userId . ':' . (time() + $expiryDays * 86400) . ':' . bin2hex(random_bytes(16));
    return 'v2_' . base64url_encode($payload . ':' . hash_hmac('sha256', 'email-login-v2:' . $payload, getMagicLinkSecret()));
}

function emailMagicClaims(string $token): ?array {
    if (!str_starts_with($token, 'v2_') || strlen($token) > 240) return null;
    $data = base64url_decode(substr($token, 3));
    if (!is_string($data) || !preg_match('/^([1-9][0-9]*):([0-9]+):([a-f0-9]{32}):([a-f0-9]{64})$/D', $data, $m)) return null;
    if ((int)$m[2] < time()) return null;
    try {
        $mac = hash_hmac('sha256', 'email-login-v2:' . $m[1] . ':' . $m[2] . ':' . $m[3], getMagicLinkSecret());
    } catch (RuntimeException $e) {
        return null;
    }
    return hash_equals($mac, $m[4]) ? ['user_id' => (int)$m[1], 'expiry' => (int)$m[2]] : null;
}

function redeemEmailMagicToken(PDO $db, string $token): bool {
    $claims = emailMagicClaims($token);
    if (!$claims) return false;
    try {
        return $db->prepare('INSERT INTO account_used_magic_links (token_hash,expires_at) VALUES (?,?)')
            ->execute([hash('sha256',$token), gmdate('Y-m-d H:i:s',$claims['expiry'])]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') return false;
        throw $e;
    }
}

/**
 * Генерирует полный URL для magic-ссылки в path-only формате /m/<token>/<b64_redirect>.
 * Path-сегменты устойчивее к мангелингу в редиректорах (Unisender, Gmail, антиспам-сканеры),
 * чем query-string — отсюда выбор формата вместо ?token=&redirect=.
 *
 * @param int $userId ID пользователя
 * @param string $targetPath Относительный путь (например, '/pages/cabinet.php?tab=events')
 * @param int $expiryDays Срок действия в днях
 * @param array $utm UTM-параметры (utm_source/medium/campaign/content/term) для атрибуции
 *                   перехода из письма. Пустые значения отбрасываются. magic-auth.php
 *                   при наличии utm_* в $_GET сохранит их в cookie _fgos_utm_* (90 дней).
 * @return string Полный URL magic-ссылки
 */
function generateMagicUrl($userId, $targetPath, $expiryDays = 7, $utm = []) {
    if (!$userId) {
        $url = SITE_URL . $targetPath;
    } else {
        $token = issueEmailMagicToken((int)$userId, (int)$expiryDays);
        $url = SITE_URL . '/m/' . $token;
        if ($targetPath !== '' && $targetPath !== '/kabinet/') {
            $url .= '/' . base64url_encode($targetPath);
        }
    }

    if (!empty($utm) && is_array($utm)) {
        $allowedKeys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'];
        $params = [];
        foreach ($allowedKeys as $key) {
            if (!empty($utm[$key])) {
                $params[$key] = (string)$utm[$key];
            }
        }
        if (!empty($params)) {
            $url .= (strpos($url, '?') !== false ? '&' : '?') . http_build_query($params);
        }
    }

    return $url;
}

/**
 * Получает секрет для HMAC
 */
function getMagicLinkSecret() {
    if (defined('MAGIC_LINK_SECRET') && !in_array(trim(MAGIC_LINK_SECRET), ['', 'default-change-me', 'your_secret_key'], true)) {
        return MAGIC_LINK_SECRET;
    }

    // Фоллбэк: используем комбинацию существующих секретов
    if (defined('YOOKASSA_SECRET_KEY') && !in_array(trim(YOOKASSA_SECRET_KEY), ['', 'default-change-me', 'your_secret_key'], true)) {
        return hash('sha256', YOOKASSA_SECRET_KEY . ':magic-link-salt');
    }
    throw new RuntimeException('Не настроен секрет ссылок из писем');
}

/**
 * base64url encode (URL-safe base64)
 */
function base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * base64url decode
 */
function base64url_decode($data) {
    return base64_decode(strtr($data, '-_', '+/'));
}

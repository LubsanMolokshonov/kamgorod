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
    $expectedHmac = hash_hmac('sha256', $payload, getMagicLinkSecret());

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
 * Генерирует полный URL для magic-ссылки
 *
 * @param int $userId ID пользователя
 * @param string $targetPath Относительный путь (например, '/pages/cabinet.php?tab=webinars')
 * @param int $expiryDays Срок действия в днях
 * @return string Полный URL magic-ссылки
 */
function generateMagicUrl($userId, $targetPath, $expiryDays = 7) {
    if (!$userId) {
        return SITE_URL . $targetPath;
    }

    $token = generateMagicToken($userId, $expiryDays);

    return SITE_URL . '/pages/magic-auth.php?token=' . urlencode($token) . '&redirect=' . urlencode($targetPath);
}

/**
 * Получает секрет для HMAC
 */
function getMagicLinkSecret() {
    if (defined('MAGIC_LINK_SECRET') && MAGIC_LINK_SECRET !== 'default-change-me') {
        return MAGIC_LINK_SECRET;
    }

    // Фоллбэк: используем комбинацию существующих секретов
    return hash('sha256', (defined('YOOKASSA_SECRET_KEY') ? YOOKASSA_SECRET_KEY : '') . ':magic-link-salt');
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

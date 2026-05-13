<?php
/**
 * Recovery Link Helper
 * HMAC-токены для cart-restore после неудачной оплаты.
 * Привязка к (order_id, user_id) — утёкший токен не открывает чужие заказы.
 */

require_once __DIR__ . '/magic-link-helper.php';

/**
 * Сгенерировать recovery-токен.
 *
 * @param int $orderId
 * @param int $userId
 * @param int $expirySeconds TTL в секундах (по умолчанию 72ч)
 * @return string base64url-encoded
 */
function generateRecoveryToken(int $orderId, int $userId, int $expirySeconds = 259200): string {
    $expiry = time() + $expirySeconds;
    $payload = $orderId . ':' . $userId . ':' . $expiry;
    $hmac = hash_hmac('sha256', $payload, getMagicLinkSecret());
    return base64url_encode($payload . ':' . $hmac);
}

/**
 * Валидировать recovery-токен.
 *
 * @param string $token
 * @return array{order_id:int, user_id:int}|false
 */
function validateRecoveryToken(string $token) {
    if ($token === '') {
        return false;
    }

    $decoded = base64url_decode($token);
    if ($decoded === false || $decoded === '') {
        return false;
    }

    $parts = explode(':', $decoded);
    if (count($parts) !== 4) {
        return false;
    }

    list($orderId, $userId, $expiry, $hmac) = $parts;

    $payload = $orderId . ':' . $userId . ':' . $expiry;
    $expected = hash_hmac('sha256', $payload, getMagicLinkSecret());

    if (!hash_equals($expected, $hmac)) {
        return false;
    }

    if (time() > (int)$expiry) {
        return false;
    }

    return [
        'order_id' => (int)$orderId,
        'user_id'  => (int)$userId,
    ];
}

/**
 * Сформировать полный URL для recovery-ссылки.
 * Path-формат /r/<token> устойчив к мангелингу в редиректорах
 * Unisender/Gmail/антиспам — по аналогии с /m/<token>.
 */
function generateRecoveryUrl(int $orderId, int $userId, int $expirySeconds = 259200): string {
    $token = generateRecoveryToken($orderId, $userId, $expirySeconds);
    return SITE_URL . '/r/' . $token;
}

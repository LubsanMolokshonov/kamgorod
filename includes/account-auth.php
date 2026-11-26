<?php
declare(strict_types=1);

/** Права на черновик или заказ относятся к одному браузеру и не дают входа в кабинет. */
function grantGuestResource(string $type, int $id, int $ownerId): void
{
    if ($id <= 0 || $ownerId <= 0) throw new InvalidArgumentException('Некорректный владелец');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['guest_resources'][$type][$id] = $ownerId;
}

function guestOwnsCartItem(string $type, int $id, int $ownerId): bool
{
    return $id > 0 && $ownerId > 0 && ($_SESSION['guest_resources'][$type][$id] ?? null) === $ownerId;
}

function grantGuestOrder(int $orderId, int $ownerId): void
{
    grantGuestResource('order', $orderId, $ownerId);
}

function guestOwnsOrder(int $orderId, int $ownerId): bool
{
    return guestOwnsCartItem('order', $orderId, $ownerId);
}

function canAccessPurchaseResource(PDO $db, string $type, int $id, int $ownerId): bool
{
    $account = verifiedAccountId($db);
    return $ownerId > 0 && ($account !== null ? $account === $ownerId : guestOwnsCartItem($type, $id, $ownerId));
}

function authorizedOrderByNumber(PDO $db, string $number): ?array
{
    $query = $db->prepare('SELECT id,user_id FROM orders WHERE order_number=?');
    $query->execute([$number]);
    $order = $query->fetch(PDO::FETCH_ASSOC);
    return $order && canAccessPurchaseResource($db, 'order', (int)$order['id'], (int)$order['user_id']) ? $order : null;
}

function safeAccountReturnPath($value): string
{
    if (!is_string($value) || strlen($value) > 1800 || !str_starts_with($value, '/')
        || str_starts_with($value, '//') || preg_match('~[\x00-\x20\x7f\\\\]~', rawurldecode($value))
        || str_starts_with(rawurldecode($value), '//')) return '/kabinet/';
    return $value;
}

/** Старые session_token и сессии от форм заявок не подтверждают владение почтой. */
function verifiedAccountId(PDO $db): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!isset($_SESSION['account_auth_version'])) {
        foreach (['cart', 'cart_certificates', 'cart_webinar_certificates', 'cart_olympiad_registrations'] as $key) {
            if (!empty($_SESSION[$key])) $_SESSION['legacy_cart'][$key] = $_SESSION[$key];
            unset($_SESSION[$key]);
        }
        unset($_SESSION['cart_db_loaded'], $_SESSION['user_id'], $_SESSION['user_email']);
        $_SESSION['account_auth_version'] = 2;
    }
    if (!empty($_SESSION['verified_account_id']) && (int)($_SESSION['user_id'] ?? 0) === $_SESSION['verified_account_id']) {
        return $_SESSION['verified_account_id'];
    }
    unset($_SESSION['user_id'], $_SESSION['user_email'], $_SESSION['verified_account_id']);
    $cookie = $_COOKIE['fgos_account'] ?? null;
    if (!is_string($cookie) || !preg_match('/^[a-f0-9]{64}$/D', $cookie)) return null;

    $stmt = $db->prepare('SELECT u.id, u.email FROM account_remember_tokens t JOIN users u ON u.id=t.user_id WHERE t.token_hash=? AND t.expires_at>?');
    $stmt->execute([hash('sha256', $cookie), gmdate('Y-m-d H:i:s')]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) return null;
    restoreVerifiedAccount($user);
    return (int)$user['id'];
}

function restoreVerifiedAccount(array $user): void
{
    $owner = (int)$user['id'];
    // Никакие права и содержимое корзины одного аккаунта не попадают в другой.
    if (isset($_SESSION['verified_account_id']) && $_SESSION['verified_account_id'] !== $owner) {
        unset($_SESSION['guest_resources'], $_SESSION['legacy_cart'], $_SESSION['guest_quiz'], $_SESSION['guest_result_details'], $_SESSION['guest_group_batches']);
        foreach (['cart', 'cart_certificates', 'cart_webinar_certificates', 'cart_olympiad_registrations'] as $k) unset($_SESSION[$k]);
    }
    foreach (($_SESSION['legacy_cart'] ?? []) as $key => $ids) {
        if (is_array($ids)) $_SESSION[$key] = array_merge($_SESSION[$key] ?? [], $ids);
    }
    unset($_SESSION['legacy_cart'], $_SESSION['cart_db_loaded']);
    $_SESSION['verified_account_id'] = $_SESSION['user_id'] = $owner;
    $_SESSION['user_email'] = $user['email'];
    $_SESSION['account_auth_version'] = 2;
}

/** Общая точка завершения подтверждённого входа. */
function establishVerifiedAccount(PDO $db, int $userId): void
{
    require_once __DIR__ . '/session.php';
    require_once __DIR__ . '/material-tracking.php';
    $stmt = $db->prepare('SELECT id,email FROM users WHERE id=?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new DomainException('Срок действия ссылки истёк');
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (!session_regenerate_id(true)) throw new RuntimeException('Не удалось обновить сессию');
    $token = bin2hex(random_bytes(32));
    $expiry = time() + 30 * 86400;
    $db->prepare('INSERT INTO account_remember_tokens (token_hash,user_id,expires_at) VALUES (?,?,?)')
        ->execute([hash('sha256', $token), $userId, gmdate('Y-m-d H:i:s', $expiry)]);
    forgetRememberedAccount($db);
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
        || (defined('SITE_URL') && parse_url(SITE_URL, PHP_URL_SCHEME) === 'https');
    setcookie('fgos_account', $token, ['expires' => $expiry, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    $_COOKIE['fgos_account'] = $token;
    restoreVerifiedAccount($user);
    mergeSessionCartToDb($userId);
    persistMaterialUtmToUser($db, $userId);
    claimAnonymousMaterials($db, $userId);
}

function forgetRememberedAccount(PDO $db): void
{
    $token = $_COOKIE['fgos_account'] ?? null;
    if (is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token)) {
        $db->prepare('DELETE FROM account_remember_tokens WHERE token_hash=?')->execute([hash('sha256',$token)]);
    }
    foreach (['fgos_account','session_token'] as $name) {
        if (isset($_COOKIE[$name])) {
            setcookie($name, '', ['expires'=>time()-3600,'path'=>'/','httponly'=>true,'samesite'=>'Lax']);
            unset($_COOKIE[$name]);
        }
    }
}

function issueAccountLoginToken(PDO $db, int $userId, string $returnPath, ?string $pendingName = null): string
{
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO account_login_tokens (token_hash,user_id,return_path,expires_at,pending_name) VALUES (?,?,?,?,?)')
        ->execute([hash('sha256',$token), $userId, safeAccountReturnPath($returnPath), gmdate('Y-m-d H:i:s', time()+900), $pendingName === null ? null : mb_substr($pendingName, 0, 55)]);
    return $token;
}

/** Атомарное погашение: из двух параллельных запросов DELETE вернёт единицу только одному. */
function redeemAccountLoginToken(PDO $db, string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return null;
    $hash = hash('sha256', $token);
    $stmt = $db->prepare('SELECT user_id,return_path,pending_name FROM account_login_tokens WHERE token_hash=? AND expires_at>?');
    $stmt->execute([$hash, gmdate('Y-m-d H:i:s')]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $delete = $db->prepare('DELETE FROM account_login_tokens WHERE token_hash=? AND expires_at>?');
    $delete->execute([$hash, gmdate('Y-m-d H:i:s')]);
    return $delete->rowCount() === 1 ? $row : null;
}

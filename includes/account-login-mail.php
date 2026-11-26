<?php
declare(strict_types=1);

require_once __DIR__ . '/account-auth.php';
require_once __DIR__ . '/rate-limit.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

/** Отправка одинакова для нового и существующего аккаунта. Права выдаются при подтверждении. */
function requestAccountLoginEmail(PDO $pdo, string $email, string $name, string $returnPath): void {
    $peer = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $publicIp = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    if (filter_var($peer, FILTER_VALIDATE_IP, $publicIp)) {
        $clientKey = 'ip:' . $peer;
    } else {
        // За внутренним прокси используем переданный публичный адрес. При отсутствии адреса лимитируем браузер,
        // чтобы общий адрес прокси не блокировал вход сразу всему порталу.
        $forwarded = (string)($_SERVER['HTTP_X_REAL_IP'] ?? '');
        $clientKey = filter_var($forwarded, FILTER_VALIDATE_IP, $publicIp) ? 'ip:' . $forwarded : 'browser:' . session_id();
    }
    if (!rateLimitConsume('account_login_' . $clientKey, 20, 900)
        || !rateLimitConsume('account_login_email:' . strtolower($email), 3, 900)) {
        throw new DomainException('Слишком много запросов. Проверьте почту или попробуйте через 15 минут.');
    }

    $find = $pdo->prepare('SELECT id FROM users WHERE email = ?');
    $find->execute([$email]);
    $id = (int)$find->fetchColumn();
    if (!$id) {
        // При одновременной регистрации извлекаем ID уже созданного пользователя.
        $create = $pdo->prepare("INSERT INTO users (email, full_name) VALUES (?, '') ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)");
        $create->execute([$email]);
        $id = (int)$pdo->lastInsertId();
    }
    $token = issueAccountLoginToken($pdo, $id, safeAccountReturnPath($returnPath), mb_substr($name, 0, 255));
    $link = rtrim(PUBLIC_SITE_URL, '/') . '/pages/confirm-login.php?token=' . rawurlencode($token);
    EmailDispatcher::send([
        'to_email' => $email,
        'subject' => 'Подтвердите вход в ФГОС-практикум',
        'text' => "Чтобы продолжить с того места, где вы остановились, откройте ссылку и подтвердите вход.\n\n" . $link
            . "\n\nЕсли вы не запрашивали вход, просто оставьте письмо без ответа.",
        'skip_tracking' => true,
        'meta' => ['email_type' => 'other', 'touchpoint_code' => 'account_login', 'user_id' => $id],
    ]);
}

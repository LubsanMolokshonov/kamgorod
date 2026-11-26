<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/session.php';
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$token = $_POST['token'] ?? $_GET['token'] ?? '';
$validFormat = is_string($token) && preg_match('/^[a-f0-9]{64}$/D', $token);
$failed = !$validFormat;
$target = safeAccountReturnPath($_SESSION['account_login_return'] ?? '/kabinet/');
$otherAccount = false;
if ($validFormat) {
    $peek = $db->prepare('SELECT user_id,return_path,expires_at FROM account_login_tokens WHERE token_hash=?');
    $peek->execute([hash('sha256',$token)]);
    $preview = $peek->fetch(PDO::FETCH_ASSOC);
    $failed = !$preview || $preview['expires_at'] < gmdate('Y-m-d H:i:s');
    if ($preview) {
        $target = safeAccountReturnPath($preview['return_path']);
        $_SESSION['account_login_return'] = $target;
        $current = verifiedAccountId($db);
        $otherAccount = $current !== null && $current !== (int)$preview['user_id'];
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? null;
    $entry = ($validFormat && is_string($csrf) && validateCSRFToken($csrf)) ? redeemAccountLoginToken($db, $token) : null;
    if ($entry) {
        if (!empty($entry['pending_name'])) {
            $db->prepare("UPDATE users SET full_name=? WHERE id=? AND (full_name IS NULL OR full_name='')")
                ->execute([$entry['pending_name'], $entry['user_id']]);
        }
        establishVerifiedAccount($db, (int)$entry['user_id']);
        $target = safeAccountReturnPath($entry['return_path']);
        if (str_starts_with($target, '/material/')) {
            try {
                require_once __DIR__ . '/../classes/UserTokens.php';
                require_once __DIR__ . '/../classes/MaterialTokenEmailChain.php';
                (new UserTokens($db))->grantSignupBonusIfNeeded((int)$entry['user_id']);
                (new MaterialTokenEmailChain($db))->schedulePreviewAbandon((int)$entry['user_id']);
            } catch (Throwable $e) {
                error_log('Подтверждение входа из материала: ' . get_class($e));
            }
        }
        header('Location: ' . $target, true, 303);
        exit;
    }
    $failed = true;
}
require_once __DIR__ . '/../includes/account-confirmation.php';
renderAccountConfirmation($validFormat ? $token : null, $failed, $otherAccount, $target);

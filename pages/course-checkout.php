<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; script-src 'self'; connect-src 'self'; form-action 'self' https:; base-uri 'none'; frame-ancestors 'none'");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/course-payment-link.php';
require_once __DIR__ . '/../classes/CourseCheckout.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if (!is_string($path) || !preg_match('~\A/oplata-kursa(?:/([1-9][0-9]{0,9}))?/?\z~', $path, $parts)) {
    http_response_code(404);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'HEAD', 'POST'], true)) {
    header('Allow: GET, HEAD, POST');
    http_response_code(405);
    exit;
}

$enrollmentId = (int)($parts[1] ?? 0);
$checkoutPath = $enrollmentId ? courseCheckoutPath($enrollmentId) : '/oplata-kursa/';
$isPost = $method === 'POST';
$isReturn = !$isPost && $enrollmentId && ($_GET['return'] ?? null) === '1';
$secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$csrfCookie = $secure ? '__Host-fgos-course-csrf' : 'fgos-course-csrf';
$grantCookie = $secure ? '__Secure-fgos-course-access' : 'fgos-course-access';
$csrf = $_COOKIE[$csrfCookie] ?? null;
$csrf = is_string($csrf) && preg_match('~\A[a-f0-9]{64}\z~', $csrf) ? $csrf : null;

$cookieOptions = ['secure' => $secure, 'httponly' => true, 'samesite' => 'Lax'];
if (!$isPost && !$isReturn && $csrf === null) {
    $csrf = bin2hex(random_bytes(32));
    setcookie($csrfCookie, $csrf, $cookieOptions + ['path' => '/']);
}

$quote = null;
$installment = null;
$guestOwner = null;
$installmentCsrf = null;
$message = '';
$conflictingAccount = false;
$showLogin = false;
$showCabinet = false;
$invalidLink = 'Ссылка устарела или недействительна. Попросите менеджера отправить актуальную ссылку.';

try {
    if ($isPost && ($csrf === null || !is_string($_POST['csrf'] ?? null) || !hash_equals($csrf, $_POST['csrf'])
        || !in_array($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '', ['', 'same-origin'], true))) {
        http_response_code(403);
        $message = 'Не удалось проверить запрос. Откройте ссылку на оплату ещё раз.';
    } elseif ($isPost && !$enrollmentId) {
        $token = $_POST['token'] ?? null;
        $claims = is_string($token) ? validateCoursePaymentLink($token) : null;
        if (!$claims || resolveCoursePaymentLinkOwner($db, $claims['enrollment_id'], $claims['deal_id']) !== $claims['user_id']) {
            http_response_code(403);
            $message = $invalidLink;
        } else {
            $accountId = getCourseCheckoutAccountId($db);
            if ($accountId !== null && $accountId !== $claims['user_id']) {
                http_response_code(403);
                $conflictingAccount = true;
            } else {
                $grantPath = courseCheckoutPath($claims['enrollment_id']);
                setcookie($grantCookie, $token, $cookieOptions + ['path' => $grantPath, 'expires' => $claims['expires_at']]);
                header('Location: ' . $grantPath, true, 303);
                exit;
            }
        }
    } elseif ($enrollmentId && !$isReturn) {
        $token = $_COOKIE[$grantCookie] ?? null;
        $claims = is_string($token) ? validateCoursePaymentLink($token) : null;
        $accountId = getCourseCheckoutAccountId($db);
        if ($claims && ($claims['enrollment_id'] !== $enrollmentId
            || resolveCoursePaymentLinkOwner($db, $enrollmentId, $claims['deal_id']) !== $claims['user_id'])) {
            $claims = null;
        }

        $guestOwner = $accountId === null ? ($_SESSION['guest_resources']['course_enrollment'][$enrollmentId] ?? null) : null;
        if ($claims && $accountId !== null && $accountId !== $claims['user_id']) {
            http_response_code(403);
            $conflictingAccount = true;
        } elseif (!$claims && $accountId === null && !$guestOwner) {
            http_response_code(403);
            $message = 'Откройте ссылку от менеджера ещё раз или войдите в личный кабинет.';
            $showLogin = true;
        } else {
            $checkout = new CourseCheckout($db, useAccountDiscounts: $accountId !== null || $claims !== null);
            $userId = $claims['user_id'] ?? $accountId ?? $guestOwner;
            $dealId = $claims['deal_id'] ?? null;
            if ($isPost) {
                $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($amount === false) {
                    http_response_code(400);
                    $message = 'Проверьте сумму и нажмите «Оплатить» ещё раз.';
                } else {
                    $result = $checkout->pay($enrollmentId, $userId, $amount, $dealId !== null, $dealId);
                    if (!empty($result['success']) && ($result['state'] ?? '') === 'paid') {
                        header('Location: ' . $checkoutPath, true, 303);
                        exit;
                    }
                    $redirect = $result['redirect_url'] ?? null;
                    if (!empty($result['success']) && is_string($redirect)
                        && (preg_match('~\Ahttps://[^\s]+\z~i', $redirect)
                            || in_array($redirect, [$checkoutPath, $checkoutPath . '?return=1'], true))) {
                        header('Location: ' . $redirect, true, 303);
                        exit;
                    }
                    $message = $result['message'] ?? 'Не удалось открыть оплату. Попробуйте ещё раз.';
                }
            }
            $quote = $checkout->quote($enrollmentId, $userId, $dealId !== null, $dealId);
            if ($quote['state'] === 'ready'
                && in_array($result['state'] ?? '', ['other_enrollment', 'deal_unavailable', 'processing', 'review'], true)) {
                $quote['state'] = $result['state'];
            }
            if ($guestOwner && !$claims && $quote['state'] === 'ready' && empty($quote['has_active_payment'])) {
                require_once __DIR__ . '/../includes/installment-helper.php';
                require_once __DIR__ . '/../includes/session.php';
                $row = (new Course($db))->getEnrollmentById($enrollmentId);
                $installment = courseInstallmentQuote($row);
                $installmentCsrf = generateCSRFToken();
            }
            $showCabinet = $accountId === $userId
                && in_array($quote['state'], ['paid', 'cancelled', 'other_enrollment', 'deal_unavailable'], true);
        }
    }
} catch (DomainException $e) {
    http_response_code(403);
    $message = 'Эта заявка недоступна. Проверьте аккаунт или обратитесь к менеджеру.';
} catch (Throwable $e) {
    http_response_code(503);
    error_log('course-checkout error for enrollment #' . $enrollmentId . ': ' . get_class($e));
    $message = 'Не удалось открыть оплату. Попробуйте ещё раз.';
}

$state = $quote['state'] ?? null;
$statusText = match ($state) {
    'paid' => 'Курс уже оплачен.',
    'processing' => 'Проверяем оплату. Обновите страницу через несколько минут.',
    'cancelled' => 'Заявка отменена. Обратитесь к менеджеру.',
    'installment' => 'По этой заявке оформляется рассрочка. Обратитесь к менеджеру.',
    'review' => 'Оплата по этой заявке требует проверки. Обратитесь к менеджеру.',
    'refunded' => 'Оплата возвращена. Обратитесь к менеджеру.',
    default => '',
};
$amount = $quote['amount_kopecks'] ?? null;
$canPay = $state === 'ready' && is_int($amount) && $amount > 0 && $csrf !== null;
$hasActivePayment = !empty($quote['has_active_payment']);
$bootstrap = !$enrollmentId && !$isPost;
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Оплата курса — ФГОС-Практикум</title>
    <link rel="stylesheet" href="/assets/css/course-checkout.css?v=<?= filemtime(__DIR__ . '/../assets/css/course-checkout.css') ?>">
    <?php if (!empty($installment['available'])): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars($installmentCsrf, ENT_QUOTES, 'UTF-8') ?>">
        <script src="/assets/js/course-cabinet.js?v=<?= filemtime(__DIR__ . '/../assets/js/course-cabinet.js') ?>" defer></script>
    <?php endif; ?>
    <?php if ($bootstrap || $isReturn): ?>
        <script src="/assets/js/course-checkout.js?v=<?= filemtime(__DIR__ . '/../assets/js/course-checkout.js') ?>" defer></script>
    <?php endif; ?>
</head>
<body>
<main class="checkout-card">
    <p class="checkout-brand">ФГОС-Практикум</p>
    <h1>Оплата курса</h1>
    <?php if ($bootstrap): ?>
        <p id="checkout-link-status" role="status">Открываем страницу оплаты…</p>
        <form id="checkout-link" method="post" action="/oplata-kursa/" hidden>
            <input type="hidden" name="csrf" value="<?= htmlspecialchars((string)$csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="token" value="">
        </form>
        <noscript><p>Включите JavaScript и снова откройте ссылку на оплату.</p></noscript>
    <?php elseif ($isReturn): ?>
        <p>Открываем результат оплаты…</p>
        <a id="checkout-return" class="checkout-button" href="<?= $checkoutPath ?>">Проверить статус</a>
    <?php elseif ($conflictingAccount): ?>
        <p>Ссылка предназначена для другого аккаунта. Выйдите из текущего аккаунта и снова откройте ссылку от менеджера.</p>
        <a class="checkout-button" href="/vyhod/">Выйти из аккаунта</a>
    <?php else: ?>
        <?php if (!empty($quote['title'])): ?>
            <p class="checkout-course"><?= htmlspecialchars($quote['title'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if (is_int($amount)): ?>
            <p class="checkout-amount"><?= number_format($amount / 100, $amount % 100 === 0 ? 0 : 2, ',', ' ') ?> ₽</p>
        <?php endif; ?>
        <?php if ($message !== ''): ?>
            <p role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($statusText !== '' && $message !== $statusText): ?>
            <p><?= $statusText ?></p>
        <?php endif; ?>
        <?php if ($canPay): ?>
            <?php if ($hasActivePayment): ?>
                <p>Платёж уже начат. Если вы оплатили курс, обновите страницу через несколько минут.</p>
            <?php endif; ?>
            <form method="post" action="<?= $checkoutPath ?>">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="amount" value="<?= $amount ?>">
                <button type="submit" class="checkout-button"><?= $hasActivePayment ? 'Продолжить оплату' : 'Оплатить' ?></button>
            </form>
            <?php if ($guestOwner && !$claims && !$hasActivePayment): ?>
                <p><a href="/vhod/?return=<?= rawurlencode($checkoutPath) ?>">Войти для применения личной скидки</a></p>
            <?php endif; ?>
            <?php if (!empty($installment['available'])): ?>
                <button type="button" class="checkout-button btn-request-installment"
                        data-enrollment-id="<?= $enrollmentId ?>" data-expected-months="<?= $installment['months'] ?>"
                        data-expected-amount-kopecks="<?= $installment['amount_kopecks'] ?>">
                    Оформить рассрочку <span class="installment-monthly-hint">≈ <?= formatRub($installment['monthly']) ?>/мес × <?= $installment['months'] ?></span>
                </button>
            <?php endif; ?>
            <?php if ($hasActivePayment): ?>
                <p><a href="<?= $checkoutPath ?>">Проверить статус</a></p>
            <?php endif; ?>
        <?php elseif ($state === 'processing'): ?>
            <a class="checkout-button" href="<?= $checkoutPath ?>">Проверить статус</a>
        <?php elseif ($showCabinet): ?>
            <a class="checkout-button" href="/kabinet/?tab=courses">В личный кабинет</a>
        <?php elseif ($showLogin): ?>
            <a class="checkout-button" href="/vhod/?return=<?= rawurlencode($checkoutPath) ?>">Войти в личный кабинет</a>
        <?php endif; ?>
        <?php if (in_array($state, ['cancelled', 'other_enrollment', 'deal_unavailable', 'review'], true)): ?>
            <p><a href="mailto:info@fgos.pro">Написать в поддержку</a></p>
        <?php endif; ?>
    <?php endif; ?>
</main>
</body>
</html>

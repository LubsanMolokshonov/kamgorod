<?php
/** Подтверждение ссылок из писем. Страница с токеном загружает только локальные стили. */
function renderAccountConfirmation(?string $loginToken, bool $invalid, bool $switching, string $returnPath): void
{
    require_once __DIR__ . '/asset-helpers.php';
    $heading = $invalid ? 'Ссылка для входа недействительна' : ($switching ? 'Переключить аккаунт?' : 'Подтвердите вход');
    $label = $switching ? 'Переключить аккаунт' : ($loginToken !== null ? 'Войти в кабинет' : 'Продолжить');
    ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $heading ?> — ФГОС-практикум</title>
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/bundle.min.css') ?>">
    <link rel="stylesheet" href="<?= assetUrl('/assets/css/auth-redesign.css') ?>">
</head>
<body class="rd-page">
<main class="auth-shell auth-confirmation">
    <section class="login-card">
        <h1><?= $heading ?></h1>
        <?php if ($invalid): ?>
            <p class="login-description">Запросите новую ссылку на свою почту, чтобы продолжить.</p>
            <a class="btn btn-primary" href="/vhod/?return=<?= rawurlencode($returnPath) ?>">Получить новую ссылку</a>
        <?php else: ?>
            <p class="login-description"><?= $switching ? 'Сейчас открыт другой кабинет. Вы можете перейти в аккаунт получателя письма.' : 'Вы войдёте в аккаунт, на почту которого пришло письмо.' ?></p>
            <form class="login-form" method="post" <?= $loginToken !== null ? 'action="/pages/confirm-login.php"' : '' ?>>
                <?php if ($loginToken !== null): ?>
                    <input type="hidden" name="token" value="<?= htmlspecialchars($loginToken, ENT_QUOTES, 'UTF-8') ?>">
                <?php endif; ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button class="btn btn-primary" type="submit"><?= $label ?></button>
                <?php if ($switching): ?><a class="btn btn-outline" href="/kabinet/">Остаться в текущем кабинете</a><?php endif; ?>
            </form>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
    <?php
}

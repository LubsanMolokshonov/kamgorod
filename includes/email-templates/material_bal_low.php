<?php
/**
 * Низкий баланс: токенов осталось мало (но больше нуля). Без скидки — мягкое
 * информационное напоминание с предложением пополнить.
 * Переменные: $user_name, $balance, $balance_url, $generator_url, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Осталось <?= (int)$balance ?> токенов</h1>
        <p>Скоро их может не хватить на новый материал</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Вы активно пользуетесь генератором — это здорово. На счёте осталось
        <strong><?= (int)$balance ?> токенов</strong>. Чтобы не прерываться на середине подготовки
        к урокам, баланс можно пополнить заранее: пакеты начинаются от небольшой суммы, а токены
        не сгорают.
    </p>

    <div class="text-center">
        <a href="<?= htmlspecialchars($balance_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Пополнить баланс →
        </a>
    </div>

    <p class="text-center text-small">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>">Продолжить генерацию материалов</a>
    </p>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

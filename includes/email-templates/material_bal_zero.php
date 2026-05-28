<?php
/**
 * Нулевой баланс + скидка: токены закончились, был недавний расход. Скидка 15% на 48ч.
 * Переменные: $user_name, $balance, $buy_url_with_discount, $discount_percent,
 *             $discount_deadline, $_sender_name, $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
$percent = (int)($discount_percent ?? 15);
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Токены закончились</h1>
        <p>Дарим скидку <?= $percent ?>% на пополнение</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Вы израсходовали все токены — значит, генератор материалов действительно пригодился.
        Чтобы продолжить без паузы, пополните баланс со <strong>скидкой <?= $percent ?>%</strong>.
        Скидка действует только на ваши пакеты и сгорает через 48 часов.
    </p>

    <div class="promo-banner">
        <h2>Скидка <?= $percent ?>% на любой пакет токенов</h2>
        <p>Действует до <?= htmlspecialchars($discount_deadline ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="text-center">
        <a href="<?= htmlspecialchars($buy_url_with_discount, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Пополнить со скидкой <?= $percent ?>% →
        </a>
    </div>

    <div class="urgency-banner critical">
        Предложение исчезнет через 48 часов — позже пакеты будут по обычной цене.
    </div>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

<?php
/**
 * Превью без оплаты, 24ч + скидка: материал готов, но не скачан. Скидка 15% на 48ч.
 * Переменные: $user_name, $balance, $locked_material_url, $buy_url_with_discount,
 *             $discount_percent, $discount_deadline, $_sender_name, $unsubscribe_url,
 *             $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
$percent = (int)($discount_percent ?? 15);
$materialUrl = $locked_material_url ?? ($balance_url ?? '#');
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Заберите свой материал</h1>
        <p>Дарим скидку <?= $percent ?>% на токены</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Ваш материал всё ещё ждёт в кабинете — вы сгенерировали его, но не скачали готовый файл.
        Чтобы помочь закончить, дарим <strong>скидку <?= $percent ?>%</strong> на пакет токенов.
        Её хватит на скачивание и ещё несколько материалов.
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

    <p class="text-center text-small">
        <a href="<?= htmlspecialchars($materialUrl, ENT_QUOTES, 'UTF-8') ?>">Открыть мой материал</a>
    </p>

    <div class="urgency-banner critical">
        Скидка сгорит через 48 часов — потом пакеты по обычной цене.
    </div>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

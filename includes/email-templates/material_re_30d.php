<?php
/**
 * Реактивация, 30 дней + скидка: молчит ≥30д. Возврат со скидкой 15% на 48ч.
 * Переменные: $user_name, $balance, $buy_url_with_discount, $generator_url,
 *             $discount_percent, $discount_deadline, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
$percent = (int)($discount_percent ?? 15);
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Возвращайтесь к генератору</h1>
        <p>Скидка <?= $percent ?>% на токены — как повод начать снова</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Вы давно не заходили в генератор материалов ФОП. Мы продолжаем улучшать его, и подготовка
        к урокам по-прежнему занимает минуту вместо вечера. Чтобы было приятнее вернуться, дарим
        <strong>скидку <?= $percent ?>%</strong> на любой пакет токенов.
    </p>

    <div class="promo-banner">
        <h2>Скидка <?= $percent ?>% на пакеты токенов</h2>
        <p>Действует до <?= htmlspecialchars($discount_deadline ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <div class="text-center">
        <a href="<?= htmlspecialchars($buy_url_with_discount, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Забрать скидку <?= $percent ?>% →
        </a>
    </div>

    <p class="text-center text-small">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>">Просто открыть генератор</a>
    </p>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

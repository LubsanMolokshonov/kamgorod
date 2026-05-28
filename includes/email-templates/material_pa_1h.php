<?php
/**
 * Превью без оплаты, 1ч: материал сгенерирован, но чистый файл не скачан.
 * Переменные: $user_name, $balance, $locked_material_url, $balance_url,
 *             $_sender_name, $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
$materialUrl = $locked_material_url ?? ($balance_url ?? '#');
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Ваш материал готов</h1>
        <p>Осталось забрать чистую версию</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Вы сгенерировали материал в конструкторе ФОП — он уже сохранён в вашем кабинете.
        Чтобы скачать готовый файл (PDF с оформлением, титулом и обложкой), откройте материал
        и нажмите «Скачать».
    </p>

    <div class="text-center">
        <a href="<?= htmlspecialchars($materialUrl, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Открыть мой материал →
        </a>
    </div>

    <p>
        На вашем балансе <strong><?= (int)($balance ?? 0) ?> токенов</strong>. Если их не хватает,
        пополнить можно за пару минут — токены не сгорают.
    </p>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

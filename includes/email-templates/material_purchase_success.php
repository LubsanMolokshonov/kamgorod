<?php
/**
 * Транзакционное письмо: токены зачислены после успешной оплаты пакета.
 * Переменные: $user_name, $package_name, $tokens_added, $balance, $generator_url,
 *             $material_types, $_sender_name, $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Токены зачислены</h1>
        <p>Спасибо за покупку — можно генерировать материалы</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Оплата прошла успешно. Пакет <strong>«<?= htmlspecialchars($package_name, ENT_QUOTES, 'UTF-8') ?>»</strong>
        зачислен на ваш счёт.
    </p>

    <div class="competition-card">
        <div class="competition-details">
            <p><strong>Начислено:</strong> +<?= (int)$tokens_added ?> токенов</p>
            <p><strong>Текущий баланс:</strong> <?= (int)$balance ?> токенов</p>
        </div>
    </div>

    <div class="text-center">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button cta-button-green">
            Перейти к генератору →
        </a>
    </div>

    <p class="text-muted text-small">
        Если потребуется чек или возникнут вопросы — просто ответьте на это письмо.
        — <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

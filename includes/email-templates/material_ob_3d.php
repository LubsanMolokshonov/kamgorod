<?php
/**
 * Онбординг, 3 дня: обзор всех типов материалов, мягкий финальный толчок.
 * Переменные: $user_name, $balance, $generator_url, $material_types, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>Что ещё умеет генератор</h1>
        <p>Не только техкарты — целый набор материалов к урокам</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Если ещё не пробовали — вот что доступно в генераторе материалов ФОП. Любой материал
        создаётся за минуту и приходит готовым файлом.
    </p>

    <?php if (!empty($material_types)): ?>
    <div class="competition-card">
        <ul class="benefits-list">
            <?php foreach ($material_types as $mt): ?>
            <li>
                <strong><?= htmlspecialchars($mt['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= strtoupper(htmlspecialchars($mt['output_format'], ENT_QUOTES, 'UTF-8')) ?>,
                <?= (int)$mt['token_cost_default'] ?> токенов
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <p>На вашем счёте <strong><?= (int)$balance ?> токенов</strong> — попробуйте формат, который ещё не использовали.</p>

    <div class="text-center">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Открыть генератор →
        </a>
    </div>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

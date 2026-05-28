<?php
/**
 * Реактивация, 14 дней: молчит ≥14д, но на счёте остались токены — напоминаем,
 * что они не сгорают, и предлагаем попробовать новый формат.
 * Переменные: $user_name, $balance, $generator_url, $material_types, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>У вас <?= (int)$balance ?> токенов</h1>
        <p>Они не сгорают — попробуйте новый формат материала</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Давно не виделись в генераторе. На вашем счёте всё ещё <strong><?= (int)$balance ?> токенов</strong>
        — они никуда не денутся. Возможно, к ближайшим урокам пригодится один из форматов, который
        вы ещё не пробовали:
    </p>

    <?php if (!empty($material_types)): ?>
    <div class="competition-card">
        <ul class="benefits-list">
            <?php foreach ($material_types as $mt): ?>
            <li><strong><?= htmlspecialchars($mt['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                — <?= strtoupper(htmlspecialchars($mt['output_format'], ENT_QUOTES, 'UTF-8')) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <div class="text-center">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Создать материал →
        </a>
    </div>

    <p class="text-muted text-small">
        С уважением, <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>, ФГОС-Практикум.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

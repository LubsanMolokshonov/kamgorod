<?php
/**
 * Онбординг, 2 часа: у новичка есть 100 бонусных токенов, но ещё нет генераций.
 * Переменные: $user_name, $balance, $generator_url, $material_types, $_sender_name,
 *             $unsubscribe_url, $site_url, $footer_reason
 */
$senderName = $_sender_name ?? 'Команда ФГОС-Практикум';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo logo-text">ФГОС-Практикум</div>
        <h1>У вас <?= (int)$balance ?> токенов в подарок</h1>
        <p>Хватит на 5–7 готовых материалов к уроку</p>
    </div>
</div>
<div class="email-content">
    <p class="greeting">Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>
    <p>
        Спасибо, что заглянули в генератор материалов ФОП. Мы начислили вам
        <strong><?= (int)$balance ?> токенов</strong> — это ваш стартовый баланс, тратить деньги
        не нужно. Создайте первый материал прямо сейчас: техкарту урока, конспект, тест или
        презентацию по ФГОС за 30–40 секунд.
    </p>

    <div class="text-center">
        <a href="<?= htmlspecialchars($generator_url, ENT_QUOTES, 'UTF-8') ?>" class="cta-button">
            Создать первый материал →
        </a>
    </div>

    <?php if (!empty($material_types)): ?>
    <div class="competition-card">
        <h3>Что можно сгенерировать</h3>
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

    <p class="text-muted text-small">
        Токены не сгорают — используйте их, когда удобно. Если что-то не получится, просто
        ответьте на это письмо, я помогу. — <?= htmlspecialchars($senderName, ENT_QUOTES, 'UTF-8') ?>
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

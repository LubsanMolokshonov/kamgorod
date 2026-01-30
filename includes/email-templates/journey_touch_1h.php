<?php
/**
 * Touch 1: 1 Hour After Registration
 * Мягкое напоминание о незавершённой регистрации
 */
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo.svg" alt="ФГОС-Практикум">
        </div>
        <h1>Вы почти завершили регистрацию!</h1>
        <p>Остался один шаг до участия в конкурсе</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы начали регистрацию на конкурс, но не завершили оплату. Ваша заявка сохранена, и вы можете продолжить в любой момент.</p>

    <div class="competition-card">
        <span class="badge">Ваша заявка</span>
        <h3><?php echo htmlspecialchars($competition_title); ?></h3>
        <div class="competition-details">
            <?php if (!empty($nomination)): ?>
            <p><strong>Номинация:</strong> <?php echo htmlspecialchars($nomination); ?></p>
            <?php endif; ?>
            <?php if (!empty($work_title)): ?>
            <p><strong>Название работы:</strong> <?php echo htmlspecialchars($work_title); ?></p>
            <?php endif; ?>
        </div>
        <div class="price-tag"><?php echo number_format($competition_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($payment_url); ?>" class="cta-button">
            Завершить регистрацию
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникли вопросы по оплате или регистрации, просто ответьте на это письмо — мы с радостью поможем!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

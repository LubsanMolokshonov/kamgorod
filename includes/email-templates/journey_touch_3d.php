<?php
/**
 * Touch 3: 3 Days After Registration
 * FOMO - создание ощущения срочности
 */
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo.svg" alt="ФГОС-Практикум">
        </div>
        <h1>Время ограничено!</h1>
        <p>Успейте получить диплом</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <div class="urgency-banner">
        <strong>Ваша регистрация на конкурс ожидает оплаты уже 3 дня</strong>
    </div>

    <p>Мы хотим напомнить, что ваша заявка на участие в конкурсе <strong>"<?php echo htmlspecialchars($competition_title); ?>"</strong> всё ещё ожидает оплаты.</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Почему стоит поторопиться:</h3>

    <ul class="benefits-list">
        <li>Получите диплом мгновенно после оплаты</li>
        <li>Пополните портфолио актуальной датой участия</li>
        <li>Другие участники уже получают свои дипломы</li>
        <li>Не упустите возможность подтвердить свой профессионализм</li>
    </ul>

    <div class="competition-card">
        <span class="badge badge-orange">Ожидает оплаты</span>
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
            Оплатить и получить диплом
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

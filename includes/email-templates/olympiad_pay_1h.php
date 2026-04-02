<?php
/**
 * Олимпиада: 1 час после заказа диплома
 * Тёплое поздравление с результатом + напоминание забрать диплом
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Ваш результат впечатляет!</h1>
        <p>Заберите свой диплом олимпиады</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы успешно прошли олимпиаду и показали отличный результат! Ваш диплом готов к оформлению.</p>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($placement_text); ?></span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Результат:</strong> <?php echo intval($score); ?> из 10 баллов</p>
            <?php if ($has_supervisor && !empty($supervisor_name)): ?>
            <p><strong>Научный руководитель:</strong> <?php echo htmlspecialchars($supervisor_name); ?></p>
            <?php endif; ?>
        </div>
        <div class="price-tag"><?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($payment_url); ?>" class="cta-button">
            Получить диплом
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникли вопросы по оплате или оформлению диплома, просто ответьте на это письмо — мы с радостью поможем!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

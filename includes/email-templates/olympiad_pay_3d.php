<?php
/**
 * Олимпиада: 3 дня после заказа диплома
 * Срочность / FOMO
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-3d';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Ваш диплом ожидает оформления</h1>
        <p>Не упустите свой результат</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <div class="urgency-banner">
        <strong>Вы прошли олимпиаду 3 дня назад, но диплом ещё не оформлен</strong>
    </div>

    <p>Вы набрали <strong><?php echo intval($score); ?> из 10 баллов</strong> и заняли <strong><?php echo htmlspecialchars($placement_text); ?></strong> в олимпиаде <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong>. Это отличный результат!</p>

    <p>Другие участники уже получили свои дипломы и пополнили портфолио. Не откладывайте — оформите диплом прямо сейчас.</p>

    <div class="competition-card">
        <span class="badge badge-orange">Ожидает оплаты</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Результат:</strong> <?php echo intval($score); ?> из 10 баллов, <?php echo htmlspecialchars($placement_text); ?></p>
            <?php if ($has_supervisor && !empty($supervisor_name)): ?>
            <p><strong>Научный руководитель:</strong> <?php echo htmlspecialchars($supervisor_name); ?> (тоже получит диплом)</p>
            <?php endif; ?>
        </div>
        <div class="price-tag"><?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button">
            Оформить диплом сейчас
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

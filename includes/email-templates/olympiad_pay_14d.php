<?php
/**
 * Олимпиада: 14 дней после заказа диплома
 * Финальное письмо с персональной скидкой 15% на 48 часов.
 * Скидка уже выписана в email_campaign_discounts в момент отправки письма
 * (см. OlympiadEmailChain::sendChainEmail), применяется автоматически в корзине.
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-14d';

$discount_rate = isset($discount_rate) ? (float)$discount_rate : 0.15;
$discount_hours = isset($discount_hours) ? (int)$discount_hours : 48;
$price_old = (int)$olympiad_price;
$price_new = (int)round($price_old * (1 - $discount_rate));
$discount_percent = (int)round($discount_rate * 100);

ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 50%, #5b21b6 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Скидка <?php echo $discount_percent; ?>% для вас</h1>
        <p>Персональное предложение на ваш диплом</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Ваш диплом олимпиады <strong>«<?php echo htmlspecialchars($olympiad_title); ?>»</strong> всё ещё ждёт оформления. Мы хотим помочь вам довести дело до конца.</p>

    <div class="urgency-banner critical" style="background: #faf5ff; border-left: 4px solid #7c3aed; color: #5b21b6;">
        <strong>Персональная скидка <?php echo $discount_percent; ?>% действует <?php echo $discount_hours; ?> часа</strong>
    </div>

    <div class="competition-card" style="border-left: 4px solid #7c3aed;">
        <span class="badge" style="background: #7c3aed;"><?php echo htmlspecialchars($placement_text); ?></span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Результат:</strong> <?php echo intval($score); ?> из 10 баллов, <?php echo htmlspecialchars($placement_text); ?></p>
        </div>
        <div style="margin-top: 16px; font-size: 18px;">
            <span style="text-decoration: line-through; color: #94a3b8;"><?php echo number_format($price_old, 0, ',', ' '); ?> &#8381;</span>
            <span style="color: #7c3aed; font-weight: 700; font-size: 24px; margin-left: 12px;"><?php echo number_format($price_new, 0, ',', ' '); ?> &#8381;</span>
        </div>
    </div>

    <p>Скидка будет <strong>применена автоматически</strong> при переходе в корзину — ничего вводить не нужно.</p>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button" style="background: linear-gradient(135deg, #7c3aed, #6d28d9);">
            Забрать диплом со скидкой
        </a>
    </div>

    <p class="text-small" style="margin-top: 30px; color: #94a3b8; text-align: center;">
        Это последнее напоминание о вашем дипломе. Скидка действует <?php echo $discount_hours; ?> часа.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

<?php
/**
 * Олимпиада: 7 дней после заказа диплома
 * Последний шанс
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-7d';
ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 50%, #991b1b 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Последний шанс!</h1>
        <p>Получите диплом олимпиады</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <div class="urgency-banner critical">
        <strong>Прошла неделя с момента прохождения олимпиады</strong>
    </div>

    <p>Мы понимаем, что у вас могут быть важные причины отложить оформление. Но мы не хотим, чтобы вы упустили возможность подтвердить свой результат официальным дипломом.</p>

    <p>Ваш результат в олимпиаде <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong>: <strong><?php echo intval($score); ?> из 10 баллов, <?php echo htmlspecialchars($placement_text); ?></strong>.</p>

    <div class="promo-banner">
        <h2>Специальное предложение</h2>
        <p style="font-size: 18px; margin: 10px 0;">При оплате 2 дипломов — третий <strong>БЕСПЛАТНО!</strong></p>
        <p style="opacity: 0.9; font-size: 14px; margin-top: 15px;">
            Пройдите ещё олимпиады и сэкономьте до <?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;
        </p>
    </div>

    <div class="competition-card">
        <span class="badge badge-orange">Требует внимания</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Результат:</strong> <?php echo intval($score); ?> из 10, <?php echo htmlspecialchars($placement_text); ?></p>
        </div>
        <div class="price-tag"><?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button cta-button-green">
            Получить диплом
        </a>

        <p style="margin-top: 15px;">
            <a href="<?php echo htmlspecialchars($site_url . '/olimpiady/?' . $utm); ?>" style="color: #2563eb; text-decoration: none; font-weight: 500;">
                Выбрать дополнительные олимпиады &rarr;
            </a>
        </p>
    </div>

    <p class="text-small" style="margin-top: 30px; color: #94a3b8; text-align: center;">
        Это последнее напоминание о вашем дипломе олимпиады.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

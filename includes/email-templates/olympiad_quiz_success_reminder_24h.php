<?php
/**
 * Олимпиада: 24ч после успешного теста — не заказал диплом
 * Напоминание оформить диплом
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-quiz-24h';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Ваш результат ждёт оформления</h1>
        <p>Не забудьте забрать диплом</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вчера вы блестяще прошли олимпиаду <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong> и заняли <strong><?php echo htmlspecialchars($placement_text); ?></strong> с результатом <strong><?php echo intval($score); ?> из 10</strong>.</p>

    <p>Но вы ещё не оформили диплом. Не упустите возможность подтвердить свой результат официальным документом!</p>

    <div class="competition-card">
        <span class="badge badge-orange">Диплом не оформлен</span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p><strong>Результат:</strong> <?php echo intval($score); ?> из 10, <?php echo htmlspecialchars($placement_text); ?></p>
        </div>
        <div class="price-tag"><?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <?php $oly_link = $olympiad_url . (strpos($olympiad_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($oly_link); ?>" class="cta-button">
            Оформить диплом
        </a>
    </div>

    <div class="promo-banner" style="margin-top: 30px;">
        <h2>Акция 2+1</h2>
        <p>При оплате 2 дипломов — третий в подарок!</p>
        <a href="<?php echo htmlspecialchars($site_url . '/olimpiady/?' . $utm); ?>" style="color: white; text-decoration: underline; display: inline-block; margin-top: 10px;">Пройти ещё олимпиады</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

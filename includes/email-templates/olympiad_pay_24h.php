<?php
/**
 * Олимпиада: 24 часа после заказа диплома
 * Преимущества получения диплома + акция 2+1
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Не упустите свой диплом!</h1>
        <p>Ваш результат олимпиады ждёт оформления</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы прошли олимпиаду <strong>"<?php echo htmlspecialchars($olympiad_title); ?>"</strong> и заняли <strong><?php echo htmlspecialchars($placement_text); ?></strong>, но ещё не оформили диплом.</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Что вы получите после оплаты:</h3>

    <ul class="benefits-list">
        <li>Официальный диплом олимпиады в PDF-формате высокого качества</li>
        <li>Мгновенное получение в личном кабинете</li>
        <li>Возможность скачать и распечатать неограниченное количество раз</li>
        <li>Подтверждение результата: <?php echo intval($score); ?> из 10 баллов, <?php echo htmlspecialchars($placement_text); ?></li>
        <?php if ($has_supervisor): ?>
        <li>Диплом для научного руководителя</li>
        <?php endif; ?>
        <li>Пополнение профессионального портфолио</li>
    </ul>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($placement_text); ?></span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="price-tag"><?php echo number_format($olympiad_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($payment_url); ?>" class="cta-button">
            Оплатить и скачать диплом
        </a>
    </div>

    <div class="promo-banner" style="margin-top: 30px;">
        <h2>Акция 2+1</h2>
        <p>При оплате 2 дипломов — третий в подарок!</p>
        <a href="<?php echo htmlspecialchars($site_url); ?>/olimpiady/" style="color: white; text-decoration: underline; display: inline-block; margin-top: 10px;">Смотреть каталог олимпиад</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

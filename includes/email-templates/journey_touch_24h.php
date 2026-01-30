<?php
/**
 * Touch 2: 24 Hours After Registration
 * Напоминание о преимуществах участия
 */
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo.svg" alt="ФГОС-Практикум">
        </div>
        <h1>Не упустите возможность!</h1>
        <p>Ваш диплом ждёт вас</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Мы заметили, что вы зарегистрировались на конкурс <strong>"<?php echo htmlspecialchars($competition_title); ?>"</strong>, но ещё не завершили оплату.</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Что вы получите после оплаты:</h3>

    <ul class="benefits-list">
        <li>Официальный диплом в PDF-формате высокого качества</li>
        <li>Мгновенное получение диплома в личном кабинете</li>
        <li>Возможность скачать и распечатать диплом неограниченное количество раз</li>
        <li>Пополнение профессионального портфолио</li>
        <li>Подтверждение участия во всероссийском конкурсе</li>
    </ul>

    <div class="competition-card">
        <span class="badge">Ваша заявка</span>
        <h3><?php echo htmlspecialchars($competition_title); ?></h3>
        <?php if (!empty($nomination)): ?>
        <div class="competition-details">
            <p><strong>Номинация:</strong> <?php echo htmlspecialchars($nomination); ?></p>
        </div>
        <?php endif; ?>
        <div class="price-tag"><?php echo number_format($competition_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($payment_url); ?>" class="cta-button">
            Получить диплом сейчас
        </a>
    </div>

    <div class="promo-banner" style="margin-top: 30px;">
        <h2>Акция 2+1</h2>
        <p>При оплате 2 конкурсов — третий в подарок!</p>
        <a href="<?php echo htmlspecialchars($site_url); ?>" style="color: white; text-decoration: underline; display: inline-block; margin-top: 10px;">Смотреть каталог конкурсов</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

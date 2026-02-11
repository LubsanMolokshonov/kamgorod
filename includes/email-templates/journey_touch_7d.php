<?php
/**
 * Touch 4: 7 Days After Registration
 * Последний шанс / специальное предложение
 */
ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 50%, #991b1b 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Последний шанс!</h1>
        <p>Специальное предложение для вас</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <div class="urgency-banner critical">
        <strong>Прошла неделя с момента вашей регистрации</strong>
    </div>

    <p>Мы понимаем, что у вас могут быть важные причины отложить оплату. Но мы не хотим, чтобы вы упустили эту возможность пополнить своё профессиональное портфолио.</p>

    <p>Ваша заявка на конкурс <strong>"<?php echo htmlspecialchars($competition_title); ?>"</strong> всё ещё ожидает оплаты.</p>

    <div class="promo-banner">
        <h2>Специальное предложение</h2>
        <p style="font-size: 18px; margin: 10px 0;">При оплате 2 конкурсов — третий <strong>БЕСПЛАТНО!</strong></p>
        <p style="opacity: 0.9; font-size: 14px; margin-top: 15px;">
            Выберите ещё конкурсы и сэкономьте до <?php echo number_format($competition_price, 0, ',', ' '); ?> &#8381;
        </p>
    </div>

    <div class="competition-card">
        <span class="badge badge-orange">Требует внимания</span>
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
        <a href="<?php echo htmlspecialchars($payment_url); ?>" class="cta-button cta-button-green">
            Завершить регистрацию
        </a>

        <p style="margin-top: 15px;">
            <a href="<?php echo htmlspecialchars($site_url); ?>" style="color: #2563eb; text-decoration: none; font-weight: 500;">
                Выбрать дополнительные конкурсы &rarr;
            </a>
        </p>
    </div>

    <p class="text-small" style="margin-top: 30px; color: #94a3b8; text-align: center;">
        Это последнее напоминание о вашей регистрации.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

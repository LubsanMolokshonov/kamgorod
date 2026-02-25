<?php
/**
 * Email Template: Publication Payment Reminder (3 days)
 * Через 3 дня после оформления сертификата, финальный шанс
 */

$email_subject = "Не упустите: акция «2+1» скоро завершится!";

$utm = 'utm_source=email&utm_campaign=pub-pay-3d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Не упустите выгоду!</h1>
        <p>Акция «2+1» скоро завершится</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Ваше свидетельство о публикации <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> по-прежнему ожидает оплаты.</p>

    <div class="info-block" style="background: #fef2f2; border-left-color: #ef4444;">
        <p style="color: #dc2626; font-weight: 600; margin-bottom: 8px;">Акция «2+1» скоро завершится!</p>
        <p style="color: #4A5568;">При оплате двух документов третий вы получаете бесплатно. Завершите оплату и воспользуйтесь предложением.</p>
    </div>

    <div class="certificate-card">
        <h3>Свидетельство о публикации</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cab_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Завершить оплату
        </a>
    </div>

    <p style="color: #64748b; font-size: 14px; text-align: center;">Всего <?php echo number_format($certificate_price, 0, '', ' '); ?> руб. — и у вас будет официальное подтверждение публикации.</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

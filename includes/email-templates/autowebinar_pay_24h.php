<?php
/**
 * Email Template: Autowebinar Payment Reminder (24 hours after order)
 * Через 24 часа после заказа сертификата, если не оплачен
 */

$email_subject = "Напоминание об оплате сертификата -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-pay-24h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Напоминание</h1>
        <p>Сертификат ожидает оплаты</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Напоминаем, что ваш сертификат по вебинару <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong> ожидает оплаты.</p>

    <div style="background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border-radius: 16px; padding: 25px; margin: 20px 0; border-left: 4px solid #3b82f6;">
        <h3 style="margin: 0 0 15px 0; color: #1e40af; font-size: 18px; font-weight: 600;">Что вы получите</h3>
        <ul style="color: #4A5568; padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 10px;">Именной сертификат на <strong><?php echo $certificate_hours; ?> часа</strong></li>
            <li style="margin-bottom: 10px;">PDF-документ для скачивания и печати</li>
            <li style="margin-bottom: 10px;">Уникальный регистрационный номер</li>
            <li>QR-код для проверки подлинности</li>
        </ul>
    </div>

    <div class="certificate-card">
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Оплатить сертификат
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

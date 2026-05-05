<?php
/**
 * Email Template: Autowebinar Payment Reminder (1 hour after order)
 * Через 1 час после заказа сертификата, если не оплачен
 */

$email_subject = "Завершите оплату сертификата -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-pay-1h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Завершите оплату</h1>
        <p>Ваш сертификат ожидает</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы оформили сертификат по вебинару <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но оплата ещё не завершена.</p>

    <div class="certificate-card">
        <h3>Сертификат участника</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            на <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Завершить оплату
        </a>
    </div>

    <div class="info-block" style="background: #f0fdf4; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #22c55e;">
        <p style="margin: 0; color: #16a34a; font-size: 15px;"><strong>После оплаты сертификат будет сформирован автоматически</strong></p>
        <p style="margin: 10px 0 0 0; color: #4A5568; font-size: 14px;">Вы сможете скачать PDF-файл из личного кабинета.</p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

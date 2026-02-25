<?php
/**
 * Email Template: Publication Payment Reminder (1 hour)
 * Через 1 час после оформления сертификата, если не оплачен
 */

$email_subject = "Завершите оплату свидетельства — 299 ₽";

$utm = 'utm_source=email&utm_campaign=pub-pay-1h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
        </div>
        <h1>Завершите оплату</h1>
        <p>Ваше свидетельство ожидает</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы оформили свидетельство о публикации <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong>, но оплата ещё не завершена.</p>

    <div class="certificate-card">
        <h3>Свидетельство о публикации</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Именной документ с уникальным номером
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cab_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Завершить оплату
        </a>
    </div>

    <div class="info-block" style="background: #f0fdf4; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #22c55e;">
        <p style="margin: 0; color: #16a34a; font-size: 15px;"><strong>После оплаты свидетельство будет сформировано автоматически</strong></p>
        <p style="margin: 10px 0 0 0; color: #4A5568; font-size: 14px;">Вы сможете скачать PDF-файл из личного кабинета.</p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

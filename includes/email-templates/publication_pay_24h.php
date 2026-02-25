<?php
/**
 * Email Template: Publication Payment Reminder (24 hours)
 * Через 24 часа после оформления сертификата, если не оплачен
 */

$email_subject = "Ваше свидетельство ожидает оплаты";

$utm = 'utm_source=email&utm_campaign=pub-pay-24h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
        </div>
        <h1>Свидетельство ожидает оплаты</h1>
        <p>Не забудьте завершить оформление</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Напоминаем, что вы оформили свидетельство о публикации <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong>, но оплата пока не завершена.</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Что вы получите после оплаты:</h3>

    <ul style="color: #4A5568; padding-left: 20px; line-height: 1.8;">
        <li style="margin-bottom: 8px;">Именное свидетельство в формате PDF</li>
        <li style="margin-bottom: 8px;">Уникальный регистрационный номер</li>
        <li style="margin-bottom: 8px;">Подтверждение публикации в педагогическом журнале</li>
        <li style="margin-bottom: 8px;">Документ для портфолио и аттестации</li>
    </ul>

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

    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 25px; margin: 25px 0; text-align: center; color: white;">
        <h3 style="margin: 0 0 8px 0; font-size: 20px;">Акция 2+1</h3>
        <p style="margin: 0; font-size: 15px; opacity: 0.95;">При оплате 2 документов — третий в подарок!</p>
        <a href="<?php echo htmlspecialchars($site_url); ?>?<?php echo $utm; ?>" style="color: white; text-decoration: underline; display: inline-block; margin-top: 10px; font-size: 14px;">Смотреть каталог конкурсов</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

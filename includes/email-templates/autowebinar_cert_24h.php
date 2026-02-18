<?php
/**
 * Email Template: Autowebinar Certificate Reminder (24 hours after quiz)
 * Через 24 часа после прохождения теста, если сертификат не заказан
 */

$email_subject = "Не забудьте оформить сертификат -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-cert-24h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Сертификат ждёт вас</h1>
        <p>Оформите документ для портфолио</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы прошли тест по автовебинару <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но ещё не оформили сертификат.</p>

    <div style="background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border-radius: 16px; padding: 25px; margin: 20px 0; border-left: 4px solid #3b82f6;">
        <h3 style="margin: 0 0 15px 0; color: #1e40af; font-size: 18px; font-weight: 600;">Зачем нужен сертификат?</h3>
        <ul style="color: #4A5568; padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 10px;">Подтверждает <strong><?php echo $certificate_hours; ?> часа</strong> повышения квалификации</li>
            <li style="margin-bottom: 10px;">Пополняет портфолио для аттестации</li>
            <li style="margin-bottom: 10px;">Содержит уникальный номер и QR-код</li>
            <li>Доступен для скачивания в личном кабинете</li>
        </ul>
    </div>

    <div class="certificate-card">
        <h3>Сертификат участника</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить сертификат
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

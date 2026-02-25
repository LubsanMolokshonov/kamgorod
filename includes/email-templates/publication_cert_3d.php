<?php
/**
 * Email Template: Publication Certificate Reminder (3 days)
 * Через 3 дня после публикации, акция «2+1»
 */

$email_subject = "Акция «2+1» — не упустите выгоду!";

$utm = 'utm_source=email&utm_campaign=pub-cert-3d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Акция «2+1»</h1>
        <p>Третий документ — в подарок!</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Ваша публикация <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> размещена в журнале, но вы ещё не оформили свидетельство.</p>

    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 30px; margin: 25px 0; text-align: center; color: white;">
        <h2 style="margin: 0 0 10px 0; font-size: 28px;">Акция 2+1</h2>
        <p style="margin: 0 0 15px 0; font-size: 16px; opacity: 0.95;">При оплате 2 документов — третий в подарок!</p>
        <p style="margin: 0; font-size: 14px; opacity: 0.85;">Комбинируйте: дипломы конкурсов + свидетельства о публикации + сертификаты вебинаров</p>
    </div>

    <p>Оформите свидетельство о публикации и объедините его с другими документами, чтобы воспользоваться акцией.</p>

    <div class="certificate-card">
        <h3>Свидетельство о публикации</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить свидетельство
        </a>
    </div>

    <div class="text-center" style="margin-top: 15px;">
        <a href="<?php echo htmlspecialchars($site_url); ?>?<?php echo $utm; ?>" style="color: #0077FF; text-decoration: none; font-weight: 500;">Смотреть каталог конкурсов &rarr;</a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

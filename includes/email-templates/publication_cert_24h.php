<?php
/**
 * Email Template: Publication Certificate Reminder (24 hours)
 * Через 24 часа после публикации, если сертификат не оформлен
 */

$email_subject = "Напоминание: оформите свидетельство о публикации";

$utm = 'utm_source=email&utm_campaign=pub-cert-24h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
        </div>
        <h1>Не забудьте оформить свидетельство</h1>
        <p>Ваша публикация ждёт документального подтверждения</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Напоминаем, что ваша публикация <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> успешно размещена в нашем журнале.</p>

    <p>Оформите свидетельство — это важный документ для вашего профессионального портфолио.</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Зачем нужно свидетельство:</h3>

    <ul style="color: #4A5568; padding-left: 20px; line-height: 1.8;">
        <li style="margin-bottom: 8px;"><strong>Аттестация</strong> — подтверждение публикационной активности</li>
        <li style="margin-bottom: 8px;"><strong>Портфолио</strong> — официальный документ с уникальным номером</li>
        <li style="margin-bottom: 8px;"><strong>Карьерный рост</strong> — дополнительные баллы при конкурсах</li>
        <li style="margin-bottom: 8px;"><strong>Мгновенное получение</strong> — PDF сразу после оплаты</li>
    </ul>

    <div class="certificate-card">
        <h3>Свидетельство о публикации</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Именной документ с уникальным номером
        </p>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить свидетельство
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

<?php
/**
 * Email Template: Publication Certificate Reminder (7 days)
 * Через 7 дней после публикации, последний шанс
 */

$email_subject = "Последний шанс: свидетельство о публикации";

$utm = 'utm_source=email&utm_campaign=pub-cert-7d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Последний шанс</h1>
        <p>Оформите свидетельство, пока актуальна акция</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Неделю назад ваша публикация <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> была размещена в нашем журнале. Вы ещё можете оформить именное свидетельство.</p>

    <div class="info-block" style="background: #fef2f2; border-left-color: #ef4444;">
        <p style="color: #dc2626; font-weight: 600; margin-bottom: 8px;">Не упустите возможность!</p>
        <p style="color: #4A5568;">Свидетельство о публикации — важный документ для портфолио и аттестации. Оформите его сейчас по выгодной цене.</p>
    </div>

    <div class="certificate-card">
        <h3>Свидетельство о публикации</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Мгновенное получение в PDF после оплаты
        </p>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить свидетельство
        </a>
    </div>

    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 16px; padding: 25px; margin: 25px 0; text-align: center; color: white;">
        <h3 style="margin: 0 0 8px 0; font-size: 20px;">Акция 2+1</h3>
        <p style="margin: 0; font-size: 15px; opacity: 0.95;">При оплате 2 документов — третий бесплатно!</p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

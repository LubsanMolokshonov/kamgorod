<?php
/**
 * Email Template: Publication Certificate Reminder (2 hours)
 * Через 2 часа после публикации, если сертификат не оформлен
 */

$email_subject = "Ваша публикация размещена! Оформите свидетельство";

$utm = 'utm_source=email&utm_campaign=pub-cert-2h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Публикация размещена!</h1>
        <p>Ваш материал уже в журнале</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Поздравляем! Ваша публикация <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> успешно прошла модерацию и размещена в нашем журнале.</p>

    <div class="webinar-card">
        <span class="badge">Опубликовано</span>
        <h3><?php echo htmlspecialchars($publication_title); ?></h3>
        <?php
        $pub_link = $publication_url . (strpos($publication_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <p style="margin-top: 15px;">
            <a href="<?php echo htmlspecialchars($pub_link); ?>" style="color: #0077FF; text-decoration: none; font-weight: 500;">Посмотреть публикацию &rarr;</a>
        </p>
    </div>

    <p>Теперь вы можете оформить <strong>именное свидетельство о публикации</strong> — официальный документ для вашего профессионального портфолио.</p>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Свидетельство о публикации</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Официальное подтверждение размещения<br>
            вашего материала в педагогическом журнале
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить свидетельство
        </a>
    </div>

    <h3 style="color: #2C3E50; margin-top: 30px;">Что включает свидетельство?</h3>
    <ul style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Ваше ФИО и название публикации</li>
        <li style="margin-bottom: 10px;">Уникальный регистрационный номер</li>
        <li style="margin-bottom: 10px;">Подтверждение публикации в СМИ</li>
        <li style="margin-bottom: 10px;">Документ для аттестации и портфолио</li>
    </ul>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

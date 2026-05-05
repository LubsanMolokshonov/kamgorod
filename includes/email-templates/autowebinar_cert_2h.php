<?php
/**
 * Email Template: Autowebinar Certificate Reminder (2 hours after quiz)
 * Через 2 часа после прохождения теста, если сертификат не заказан
 */

$email_subject = "Вы прошли тест! Оформите сертификат -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-cert-2h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Поздравляем!</h1>
        <p>Тест успешно пройден</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Поздравляем! Вы успешно прошли тест по видеолекции <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>.</p>

    <p>Теперь вы можете оформить именной сертификат участника.</p>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Сертификат участника вебинара</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Официальный именной сертификат<br>
            на <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Документ с уникальным номером для портфолио
        </p>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить сертификат
        </a>
    </div>

    <h3 style="color: #2C3E50; margin-top: 30px;">Что включает сертификат?</h3>
    <ul style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Ваше ФИО и название вебинара</li>
        <li style="margin-bottom: 10px;">Уникальный регистрационный номер</li>
        <li style="margin-bottom: 10px;"><?php echo $certificate_hours; ?> академических часа для аттестации</li>
        <li style="margin-bottom: 10px;">QR-код для проверки подлинности</li>
    </ul>

    <?php if ($speaker_name): ?>
    <div class="speaker-card" style="margin-top: 30px;">
        <?php if ($speaker_photo): ?>
        <img src="<?php echo htmlspecialchars($speaker_photo); ?>" alt="<?php echo htmlspecialchars($speaker_name); ?>" class="speaker-photo">
        <?php endif; ?>
        <div class="speaker-info">
            <p style="color: #718096; font-size: 12px; margin-bottom: 5px;">Спикер вебинара</p>
            <h4><?php echo htmlspecialchars($speaker_name); ?></h4>
            <?php if ($speaker_position): ?>
            <p><?php echo htmlspecialchars($speaker_position); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

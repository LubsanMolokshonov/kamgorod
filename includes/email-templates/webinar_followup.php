<?php
/**
 * Email Template: Webinar Follow-up
 * Отправляется через 3 часа после начала - благодарность, запись, сертификат
 */

$email_subject = "Спасибо за участие в вебинаре! Запись и сертификат";

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="display: flex; align-items: center; justify-content: center; gap: 20px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 50px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.svg" alt="Каменный Город" style="height: 40px;">
        </div>
        <h1>Спасибо за участие!</h1>
        <p>Вебинар завершён</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Благодарим вас за участие в вебинаре «<?php echo htmlspecialchars($webinar_title); ?>»!</p>

    <p>Надеемся, что материал был полезным и вы узнали много нового для своей профессиональной деятельности.</p>

    <?php if ($video_url): ?>
    <div class="webinar-card">
        <span class="badge" style="background: #dcfce7; color: #16a34a;">Запись доступна</span>
        <h3>🎬 Смотреть запись вебинара</h3>
        <p style="color: #4A5568; margin-bottom: 20px;">Запись доступна для просмотра в любое удобное время.</p>
        <a href="<?php echo htmlspecialchars($video_url); ?>" class="cta-button cta-button-green" style="display: inline-block;">
            Смотреть запись
        </a>
    </div>
    <?php else: ?>
    <div class="info-block">
        <p><strong>📹 Запись вебинара</strong> появится в вашем личном кабинете в течение 24 часов. Мы пришлём уведомление, когда она будет готова.</p>
    </div>
    <?php endif; ?>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>🏆 Получите сертификат участника</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Официальный именной сертификат<br>
            на <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Документ с уникальным номером для портфолио
        </p>
        <a href="<?php echo htmlspecialchars($certificate_url); ?>" class="cta-button" style="background: linear-gradient(135deg, #d97706 0%, #b45309 100%); box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Получить сертификат
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

    <div class="text-center" style="margin-top: 30px; padding-top: 30px; border-top: 1px solid #e2e8f0;">
        <p style="color: #718096; margin-bottom: 15px;">Следите за новыми вебинарами в личном кабинете</p>
        <a href="<?php echo htmlspecialchars($cabinet_url); ?>" class="cta-button cta-button-secondary">
            Перейти в личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

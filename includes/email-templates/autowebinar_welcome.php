<?php
/**
 * Email Template: Autowebinar Welcome
 * Отправляется сразу после регистрации на автовебинар
 */

$email_subject = "Добро пожаловать на видеолекцию: {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-welcome';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Вы зарегистрированы!</h1>
        <p>Видеолекция доступна прямо сейчас</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы успешно зарегистрировались на видеолекцию. Запись доступна в любое удобное для вас время.</p>

    <div class="webinar-card">
        <span class="badge">Бесплатная видеолекция</span>
        <h3><?php echo htmlspecialchars($webinar_title); ?></h3>
        <?php if ($speaker_name): ?>
        <div class="webinar-details">
            <p>
                <span class="icon">👤</span>
                Спикер: <?php echo htmlspecialchars($speaker_name); ?>
                <?php if ($speaker_position): ?>
                    — <?php echo htmlspecialchars($speaker_position); ?>
                <?php endif; ?>
            </p>
        </div>
        <?php endif; ?>
    </div>

    <div class="text-center" style="margin: 25px 0;">
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Смотреть видеолекцию
        </a>
    </div>

    <div class="info-block" style="background: #FDF6E3; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #F4C430;">
        <p style="margin: 0 0 10px 0; color: #92400e; font-size: 15px;"><strong>Как получить сертификат?</strong></p>
        <ol style="margin: 0; padding-left: 20px; color: #92400e; font-size: 14px;">
            <li style="margin-bottom: 8px;">Посмотрите запись вебинара</li>
            <li style="margin-bottom: 8px;">Пройдите короткий тест (5 вопросов)</li>
            <li>Оформите сертификат на <?php echo $certificate_hours; ?> академических часа</li>
        </ol>
    </div>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Сертификат участника</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Официальный именной сертификат<br>
            на <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px;">
            Документ с уникальным номером для портфолио и аттестации
        </p>
    </div>

    <?php if ($speaker_name && $speaker_photo): ?>
    <div class="speaker-card" style="margin-top: 30px;">
        <img src="<?php echo htmlspecialchars($speaker_photo); ?>" alt="<?php echo htmlspecialchars($speaker_name); ?>" class="speaker-photo">
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

<?php
/**
 * Email Template: Webinar Reminder 15 minutes before
 * Отправляется за 15 минут до начала - ПОСЛЕДНЕЕ НАПОМИНАНИЕ
 */

$email_subject = "Через 15 минут начало вебинара!";

$utm = 'utm_source=email&utm_campaign=webinar-reminder-15min';
ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Вебинар начинается!</h1>
        <p>Через 15 минут — в <?php echo $webinar_time; ?> МСК</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>До начала вебинара «<?php echo htmlspecialchars($webinar_title); ?>» осталось <strong>15 минут</strong>!</p>

    <div class="broadcast-link-box">
        <h2>🎬 Войти на трансляцию</h2>
        <p style="margin-bottom: 20px; opacity: 0.9;">Нажмите кнопку прямо сейчас, чтобы занять место</p>
        <a href="<?php echo htmlspecialchars($broadcast_url); ?>" class="cta-button" style="font-size: 18px; padding: 20px 60px;">
            ВОЙТИ НА ВЕБИНАР
        </a>
        <p class="broadcast-url-text">
            Если кнопка не работает, скопируйте ссылку:<br>
            <?php echo htmlspecialchars($broadcast_url); ?>
        </p>
    </div>

    <div class="webinar-card">
        <h3><?php echo htmlspecialchars($webinar_title); ?></h3>
        <div class="webinar-details">
            <p>
                <span class="icon">🕐</span>
                <strong>Начало: <?php echo $webinar_time; ?> МСК</strong>
            </p>
            <p>
                <span class="icon">⏱️</span>
                Продолжительность: <?php echo $webinar_duration; ?> минут
            </p>
            <?php if ($speaker_name): ?>
            <p>
                <span class="icon">👤</span>
                Спикер: <?php echo htmlspecialchars($speaker_name); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <p style="color: #718096; font-size: 14px; margin-top: 30px;">
        Не можете присутствовать? Не переживайте — запись вебинара будет доступна в вашем личном кабинете после окончания трансляции.
    </p>

    <div class="text-center" style="margin-top: 20px;">
        <?php $cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($cab_link); ?>" class="cta-button cta-button-secondary">
            Личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

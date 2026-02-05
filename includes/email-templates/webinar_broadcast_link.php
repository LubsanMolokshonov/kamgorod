<?php
/**
 * Email Template: Webinar Broadcast Link
 * Отправляется за 1 час до начала - ГЛАВНАЯ ССЫЛКА НА ТРАНСЛЯЦИЮ
 */

$email_subject = "Через 1 час начало! Ссылка на вебинар внутри";

ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%);">
    <div class="email-header-content">
        <div class="logo" style="display: flex; align-items: center; justify-content: center; gap: 20px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 50px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.svg" alt="Каменный Город" style="height: 40px;">
        </div>
        <h1>Вебинар через 1 час!</h1>
        <p>Начало в <?php echo $webinar_time; ?> МСК</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Через <strong>1 час</strong> начнётся вебинар «<?php echo htmlspecialchars($webinar_title); ?>».</p>

    <div class="broadcast-link-box">
        <h2>🎬 Войти на трансляцию</h2>
        <p style="margin-bottom: 20px; opacity: 0.9;">Нажмите кнопку за 5 минут до начала</p>
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

    <div class="info-block">
        <p><strong>💡 Совет:</strong> Войдите на трансляцию за 5 минут до начала, чтобы проверить звук и изображение.</p>
    </div>

    <p style="color: #718096; font-size: 14px; margin-top: 30px;">
        Не можете присутствовать? Не переживайте — запись вебинара будет доступна в вашем личном кабинете после окончания трансляции.
    </p>

    <div class="text-center" style="margin-top: 20px;">
        <a href="<?php echo htmlspecialchars($cabinet_url); ?>" class="cta-button cta-button-secondary">
            Личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

<?php
/**
 * Email Template: Webinar Confirmation
 * Отправляется сразу после регистрации
 */

$email_subject = "Вы зарегистрированы на вебинар: {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=webinar-confirmation';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Вы зарегистрированы!</h1>
        <p>Ждём вас на вебинаре</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы успешно зарегистрировались на бесплатный вебинар. Сохраните дату в календаре, чтобы не пропустить!</p>

    <div class="webinar-card">
        <span class="badge">Бесплатный вебинар</span>
        <h3><?php echo htmlspecialchars($webinar_title); ?></h3>
        <div class="webinar-details">
            <p>
                <span class="icon">📅</span>
                <strong><?php echo $webinar_datetime_full; ?></strong>
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

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($google_calendar_url ?? $calendar_url); ?>" class="cta-button" target="_blank">
            📅 Добавить в календарь
        </a>
    </div>

    <div class="info-block">
        <p><strong>Важно:</strong> Ссылка на трансляцию придёт вам на почту за 1 час до начала вебинара. Также вы можете найти её в личном кабинете.</p>
    </div>

    <?php if ($webinar_description): ?>
    <h3 style="color: #2C3E50; margin-top: 30px;">О чём вебинар?</h3>
    <p style="color: #4A5568;"><?php echo nl2br(htmlspecialchars($webinar_description)); ?></p>
    <?php endif; ?>

    <?php if ($speaker_name && $speaker_photo): ?>
    <div class="speaker-card">
        <img src="<?php echo htmlspecialchars($speaker_photo); ?>" alt="<?php echo htmlspecialchars($speaker_name); ?>" class="speaker-photo">
        <div class="speaker-info">
            <h4><?php echo htmlspecialchars($speaker_name); ?></h4>
            <?php if ($speaker_position): ?>
            <p><?php echo htmlspecialchars($speaker_position); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="text-center" style="margin-top: 30px;">
        <?php $cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($cab_link); ?>" class="cta-button cta-button-secondary">
            Перейти в личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

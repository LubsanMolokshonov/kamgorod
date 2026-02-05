<?php
/**
 * Email Template: Webinar Reminder 24h
 * Отправляется за 24 часа до вебинара
 */

$email_subject = "Завтра вебинар: {$webinar_title}";

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="display: flex; align-items: center; justify-content: center; gap: 20px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 50px;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.svg" alt="Каменный Город" style="height: 40px;">
        </div>
        <h1>Завтра вебинар!</h1>
        <p>Не пропустите — <?php echo $webinar_time; ?> МСК</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Напоминаем, что <strong>завтра</strong> состоится вебинар, на который вы зарегистрировались.</p>

    <div class="webinar-card">
        <span class="badge">Завтра</span>
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
        </div>
    </div>

    <?php if ($speaker_name): ?>
    <h3 style="color: #2C3E50; margin-top: 30px;">Ваш спикер</h3>
    <div class="speaker-card">
        <?php if ($speaker_photo): ?>
        <img src="<?php echo htmlspecialchars($speaker_photo); ?>" alt="<?php echo htmlspecialchars($speaker_name); ?>" class="speaker-photo">
        <?php endif; ?>
        <div class="speaker-info">
            <h4><?php echo htmlspecialchars($speaker_name); ?></h4>
            <?php if ($speaker_position): ?>
            <p><?php echo htmlspecialchars($speaker_position); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($webinar_description): ?>
    <h3 style="color: #2C3E50; margin-top: 30px;">Что вас ждёт</h3>
    <p style="color: #4A5568;"><?php echo nl2br(htmlspecialchars($webinar_description)); ?></p>
    <?php endif; ?>

    <div class="info-block">
        <p><strong>📧 Ссылка на трансляцию</strong> придёт вам на почту за 1 час до начала. Проверьте папку «Спам», если письмо не придёт.</p>
    </div>

    <h3 style="color: #2C3E50; margin-top: 30px;">Как подготовиться</h3>
    <ul style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Проверьте стабильность интернет-соединения</li>
        <li style="margin-bottom: 10px;">Подготовьте вопросы для спикера</li>
        <li style="margin-bottom: 10px;">Выделите время без отвлечений</li>
    </ul>

    <div class="text-center" style="margin-top: 30px;">
        <a href="<?php echo htmlspecialchars($webinar_url); ?>" class="cta-button">
            Подробнее о вебинаре
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

<?php
/**
 * Email Template: Webinar Invitation
 * Приглашение на вебинар для пользователей, которые ещё не зарегистрированы
 */

$utm = 'utm_source=email&utm_medium=invite&utm_campaign=webinar-nastavnik-2026';
$webinar_link = $site_url . '/vebinar/' . $webinar_slug . '?' . $utm;

$email_subject = "Приглашаем на бесплатный вебинар: {$webinar_title}";

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Приглашаем на бесплатный вебинар!</h1>
        <p>Уже завтра — не пропустите</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>!</p>

    <p>Приглашаем вас на бесплатный вебинар для педагогов. Регистрация займёт всего 30 секунд!</p>

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
        <a href="<?php echo htmlspecialchars($webinar_link); ?>" class="cta-button cta-button-green" style="font-size: 18px; padding: 18px 50px;">
            Зарегистрироваться бесплатно
        </a>
    </div>

    <?php if ($webinar_description): ?>
    <h3 style="color: #2C3E50; margin-top: 30px;">О чём вебинар?</h3>
    <p style="color: #4A5568;"><?php echo nl2br(htmlspecialchars($webinar_description)); ?></p>
    <?php endif; ?>

    <?php if ($speaker_name): ?>
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

    <div class="certificate-card">
        <h3>🎓 Именной сертификат</h3>
        <p style="color: #92400e; margin: 5px 0;">После вебинара вы сможете получить именной сертификат на <?php echo $certificate_hours; ?> часа</p>
        <div class="price"><?php echo number_format($certificate_price, 0, ',', ' '); ?> ₽</div>
    </div>

    <div class="info-block">
        <p><strong>Важно:</strong> Количество мест ограничено. Зарегистрируйтесь сейчас, чтобы гарантировать себе место и получить ссылку на трансляцию.</p>
    </div>

    <div class="text-center" style="margin-top: 30px;">
        <a href="<?php echo htmlspecialchars($webinar_link); ?>" class="cta-button">
            Узнать подробнее и зарегистрироваться
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

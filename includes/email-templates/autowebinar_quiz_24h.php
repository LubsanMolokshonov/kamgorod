<?php
/**
 * Email Template: Autowebinar Quiz Reminder (24 hours)
 * Через 24 часа после регистрации, если тест не пройден
 */

$email_subject = "Пройдите тест и получите сертификат -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-quiz-24h';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Пройдите тест</h1>
        <p>И получите сертификат</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы зарегистрировались на видеолекцию <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но ещё не прошли тест.</p>

    <p>Чтобы получить именной сертификат на <strong><?php echo $certificate_hours; ?> академических часа</strong>, нужно:</p>

    <ol style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Посмотреть запись вебинара</li>
        <li style="margin-bottom: 10px;">Ответить на 5 вопросов теста (нужно 4 из 5 правильных)</li>
        <li>Оформить сертификат</li>
    </ol>

    <div class="text-center" style="margin: 25px 0;">
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Перейти к видеолекции
        </a>
    </div>

    <div class="info-block" style="background: #f0fdf4; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #22c55e;">
        <p style="margin: 0; color: #16a34a; font-size: 15px;"><strong>Тест можно проходить повторно!</strong></p>
        <p style="margin: 10px 0 0 0; color: #4A5568; font-size: 14px;">Если не получится с первого раза — ничего страшного, попробуйте ещё раз.</p>
    </div>

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

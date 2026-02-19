<?php
/**
 * Email Template: Autowebinar Quiz Reminder (7 days)
 * Через 7 дней после регистрации, если тест не пройден. Последний шанс.
 */

$email_subject = "Последний шанс получить сертификат -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-quiz-7d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Последнее напоминание</h1>
        <p>Пройдите тест по вебинару</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Неделю назад вы зарегистрировались на видеолекцию <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но тест до сих пор не пройден.</p>

    <div class="info-block" style="background: #FEF2F2; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #EF4444;">
        <p style="margin: 0; color: #991B1B; font-size: 15px;"><strong>Это последнее напоминание</strong></p>
        <p style="margin: 10px 0 0 0; color: #7F1D1D; font-size: 14px;">Пройдите тест сейчас, чтобы не упустить возможность получить сертификат на <?php echo $certificate_hours; ?> часа для вашего профессионального портфолио.</p>
    </div>

    <p>Это займёт всего несколько минут:</p>
    <ol style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 8px;">Посмотрите запись (можно на ускоренной перемотке)</li>
        <li style="margin-bottom: 8px;">Ответьте на 5 вопросов</li>
        <li>Получите сертификат</li>
    </ol>

    <div class="text-center" style="margin: 25px 0;">
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);">
            Пройти тест сейчас
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

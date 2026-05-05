<?php
/**
 * Email Template: Autowebinar Quiz Reminder (3 days)
 * Через 3 дня после регистрации, если тест не пройден
 */

$email_subject = "Напоминание: пройдите тест по вебинару -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-quiz-3d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Напоминание</h1>
        <p>Тест по вебинару ждёт вас</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Несколько дней назад вы зарегистрировались на видеолекцию <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>.</p>

    <p>Тест ещё не пройден. Напоминаем, зачем это нужно:</p>

    <div style="background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border-radius: 16px; padding: 25px; margin: 20px 0;">
        <h3 style="margin: 0 0 15px 0; color: #1e40af; font-size: 18px; font-weight: 600;">Преимущества сертификата</h3>
        <ul style="color: #4A5568; padding-left: 20px; margin: 0;">
            <li style="margin-bottom: 10px;"><strong><?php echo $certificate_hours; ?> академических часа</strong> для аттестации</li>
            <li style="margin-bottom: 10px;">Уникальный регистрационный номер</li>
            <li style="margin-bottom: 10px;">QR-код для проверки подлинности</li>
            <li>Подходит для портфолио педагога</li>
        </ul>
    </div>

    <div class="text-center" style="margin: 25px 0;">
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0065B1 0%, #004d8a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 101, 177, 0.4);">
            Посмотреть вебинар и пройти тест
        </a>
    </div>

    <p style="color: #718096; font-size: 14px; text-align: center;">Тест состоит из 5 вопросов. Можно проходить повторно.</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

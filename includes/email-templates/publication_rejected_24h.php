<?php
/**
 * Email Template: Publication Rejected - Retry (24 hours)
 * Через 24 часа после отклонения модерацией, если нет другой одобренной публикации
 */

$email_subject = "Попробуйте опубликовать снова!";

$utm = 'utm_source=email&utm_campaign=pub-rejected-24h';

ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Попробуйте ещё раз!</h1>
        <p>Мы подскажем, какие материалы подходят</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>К сожалению, ваша публикация <strong>«<?php echo htmlspecialchars($publication_title); ?>»</strong> не прошла модерацию.</p>

    <?php if (!empty($moderation_comment)): ?>
    <div class="info-block" style="background: #fef2f2; border-left-color: #ef4444;">
        <p style="color: #dc2626; font-weight: 600; margin-bottom: 8px;">Причина отклонения:</p>
        <p style="color: #4A5568;"><?php echo htmlspecialchars($moderation_comment); ?></p>
    </div>
    <?php endif; ?>

    <p>Не расстраивайтесь! Отправьте новый материал, соответствующий педагогической тематике. Вот примеры того, что мы публикуем:</p>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Подходящие материалы:</h3>

    <ul style="color: #4A5568; padding-left: 20px; line-height: 1.8;">
        <li style="margin-bottom: 8px;">Методические разработки и конспекты уроков</li>
        <li style="margin-bottom: 8px;">Рабочие программы и планирование</li>
        <li style="margin-bottom: 8px;">Статьи по педагогике, дидактике, психологии</li>
        <li style="margin-bottom: 8px;">Сценарии мероприятий и классных часов</li>
        <li style="margin-bottom: 8px;">Исследовательские и проектные работы</li>
        <li style="margin-bottom: 8px;">Олимпиадные задания и тесты</li>
        <li style="margin-bottom: 8px;">Презентации к урокам и занятиям</li>
    </ul>

    <div class="text-center" style="margin: 30px 0;">
        <?php
        $sub_link = $submit_url . (strpos($submit_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($sub_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 119, 255, 0.4);">
            Отправить новую публикацию
        </a>
    </div>

    <div class="info-block">
        <p style="color: #92400e; font-weight: 600; margin-bottom: 8px;">Публикация бесплатная</p>
        <p style="color: #4A5568;">Размещение материала в нашем журнале полностью бесплатно. Оплата нужна только за оформление именного свидетельства.</p>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

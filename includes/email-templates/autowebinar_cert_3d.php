<?php
/**
 * Email Template: Autowebinar Certificate Reminder (3 days after quiz)
 * Через 3 дня после прохождения теста, если сертификат не заказан. Финальное.
 */

$email_subject = "Ваш сертификат ждёт оформления -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-cert-3d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Последнее напоминание</h1>
        <p>Оформите сертификат</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы успешно прошли тест по вебинару <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но сертификат так и не оформлен.</p>

    <p>Это последнее напоминание. Не упустите возможность получить документ для вашего профессионального портфолио!</p>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Сертификат на <?php echo $certificate_hours; ?> академических часа</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            С уникальным номером и QR-кодом для проверки подлинности
        </p>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить сертификат
        </a>
    </div>

    <div class="text-center" style="margin-top: 20px;">
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button cta-button-secondary" style="display: inline-block; background: #ebebf0; color: #0077FF; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600;">
            Перейти в личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

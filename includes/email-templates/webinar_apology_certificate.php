<?php
/**
 * Email Template: Apology - Certificate Technical Issue Fixed
 * Разовое письмо-извинение для пользователей, столкнувшихся с ошибкой оформления сертификата
 */

$email_subject = "Приносим извинения — оформление сертификата восстановлено";

$utm = 'utm_source=email&utm_campaign=apology_certificate';

ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Приносим извинения</h1>
        <p>Техническая проблема устранена</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>После вебинара <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong> вы переходили по ссылке для оформления сертификата, но столкнулись с технической ошибкой.</p>

    <div class="info-block" style="background: #fef2f2; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #ef4444;">
        <p style="margin: 0; color: #991b1b; font-size: 15px;"><strong>Что произошло:</strong> из-за технического сбоя на нашей стороне страница оформления сертификата была временно недоступна. Мы приносим искренние извинения за неудобства.</p>
    </div>

    <div style="background: #f0fdf4; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #22c55e;">
        <p style="margin: 0; color: #166534; font-size: 15px;"><strong>Проблема полностью устранена!</strong> Сейчас всё работает корректно — вы можете оформить сертификат прямо сейчас.</p>
    </div>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Оформите сертификат участника</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            Официальный именной сертификат<br>
            на <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Документ с уникальным номером для портфолио
        </p>
        <?php
        $cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cert_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #d97706 0%, #b45309 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(217, 119, 6, 0.4);">
            Оформить сертификат
        </a>
    </div>

    <p style="color: #4A5568; margin-top: 25px;">Если у вас возникнут вопросы, напишите нам на <a href="mailto:info@fgos.pro" style="color: #0077FF;">info@fgos.pro</a> — мы обязательно поможем.</p>

    <p style="color: #4A5568;">С уважением,<br>Команда ФГОС-Практикум</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

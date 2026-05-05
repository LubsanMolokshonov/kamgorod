<?php
/**
 * Email Template: Apology - Certificate Download Fixed + PDF Attached
 * Письмо-извинение для пользователей, оплативших сертификат, но не сумевших его скачать.
 * PDF сертификата прикрепляется к письму.
 */

$email_subject = "Ваш сертификат готов — приносим извинения за задержку";

$utm = 'utm_source=email&utm_campaign=apology_download_certificate';

ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #1e40af 0%, #3b82f6 50%, #60a5fa 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Ваш сертификат готов!</h1>
        <p>Приносим извинения за задержку</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы оплатили сертификат участника вебинара <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong>, но из-за технического сбоя на нашей стороне скачивание было временно недоступно.</p>

    <div class="info-block" style="background: #fef2f2; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #ef4444;">
        <p style="margin: 0; color: #991b1b; font-size: 15px;"><strong>Приносим искренние извинения!</strong> Мы понимаем, как это неприятно. Проблема полностью устранена.</p>
    </div>

    <div style="background: #f0fdf4; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #22c55e;">
        <p style="margin: 0; color: #166534; font-size: 15px;">
            <strong>Ваш сертификат прикреплён к этому письму</strong> — вы можете сохранить его прямо сейчас.
        </p>
    </div>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Сертификат участника вебинара</h3>
        <p style="color: #92400e; margin-bottom: 10px;">
            <?php echo htmlspecialchars($user_name); ?><br>
            <strong><?php echo $certificate_hours; ?> академических часа</strong>
        </p>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 5px;">
            № <?php echo htmlspecialchars($certificate_number); ?>
        </p>
    </div>

    <p style="color: #4A5568;">Также вы всегда можете скачать сертификат в личном кабинете:</p>

    <div class="text-center" style="text-align: center; margin: 25px 0;">
        <?php
        $cabinet_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cabinet_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #0077FF 0%, #0066DD 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 119, 255, 0.4);">
            Перейти в личный кабинет
        </a>
    </div>

    <p style="color: #4A5568;">Если у вас возникнут вопросы, напишите нам на <a href="mailto:info@fgos.pro" style="color: #0077FF;">info@fgos.pro</a> — мы обязательно поможем.</p>

    <p style="color: #4A5568;">С уважением,<br>Команда ФГОС-Практикум</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

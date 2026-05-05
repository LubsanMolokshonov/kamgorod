<?php
/**
 * Email Template: Запись вебинара «Читательские марафоны»
 * Рассылка зарегистрированным участникам: запись + презентация + анкета + сертификат + следующий вебинар
 */

$email_subject = "Запись вебинара «Читательские марафоны» — смотрите бесплатно!";

$utm = 'utm_source=email&utm_campaign=recording_chitatelskie_marafony';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
        </div>
        <h1>Запись вебинара готова!</h1>
        <p>Смотрите в удобное время</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>25 февраля состоялся вебинар «<?php echo htmlspecialchars($webinar_title); ?>». Запись уже доступна — смотрите в любое удобное время!</p>

    <div class="broadcast-link-box">
        <h2>Смотреть запись вебинара</h2>
        <p style="opacity: 0.9; margin: 0 0 20px 0;">Бесплатная запись доступна прямо сейчас</p>
        <a href="<?php echo htmlspecialchars($recording_url); ?>?<?php echo $utm; ?>" class="cta-button" style="background: white; color: #16a34a !important; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.2);">
            СМОТРЕТЬ ЗАПИСЬ
        </a>
    </div>

    <div style="background: linear-gradient(135deg, #E8F1FF 0%, #f8fafc 100%); border-radius: 16px; padding: 25px; margin: 25px 0; border-left: 4px solid #0077FF;">
        <h3 style="margin: 0 0 15px 0; color: #0077FF; font-size: 18px; font-weight: 600;">Полезные материалы</h3>
        <p style="color: #4A5568; margin: 10px 0;">
            <span style="margin-right: 8px;">📎</span>
            <a href="<?php echo htmlspecialchars($presentation_url); ?>?<?php echo $utm; ?>" style="color: #0077FF; text-decoration: none; font-weight: 500;">Скачать презентацию с полезными материалами</a>
        </p>
        <p style="color: #4A5568; margin: 10px 0;">
            <span style="margin-right: 8px;">📝</span>
            <a href="<?php echo htmlspecialchars($feedback_url); ?>?<?php echo $utm; ?>" style="color: #0077FF; text-decoration: none; font-weight: 500;">Заполнить анкету обратной связи</a>
        </p>
    </div>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Получите сертификат участника</h3>
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
            Получить сертификат
        </a>
    </div>

    <h3 style="color: #2C3E50; margin-top: 30px;">Что включает сертификат?</h3>
    <ul style="color: #4A5568; padding-left: 20px;">
        <li style="margin-bottom: 10px;">Ваше ФИО и название вебинара</li>
        <li style="margin-bottom: 10px;">Уникальный регистрационный номер</li>
        <li style="margin-bottom: 10px;"><?php echo $certificate_hours; ?> академических часа для аттестации</li>
        <li style="margin-bottom: 10px;">QR-код для проверки подлинности</li>
    </ul>

    <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border-radius: 16px; padding: 25px; margin: 30px 0; text-align: center; border-left: 4px solid #8b5cf6;">
        <span class="badge" style="display:inline-block; background: #ede9fe; color: #7c3aed; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 10px;">Следующий вебинар</span>
        <h3 style="margin: 0 0 10px 0; color: #7c3aed; font-size: 18px; font-weight: 600;">Приглашаем на следующий вебинар</h3>
        <p style="color: #4A5568; margin-bottom: 5px; font-weight: 600;">«<?php echo htmlspecialchars($next_webinar_title); ?>»</p>
        <p style="color: #718096; margin-bottom: 20px; font-size: 14px;">5 марта в 14:00 по Москве<br>Продолжите повышение квалификации вместе с нами!</p>
        <a href="<?php echo htmlspecialchars($next_webinar_url); ?>?<?php echo $utm; ?>" style="display: inline-block; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(139, 92, 246, 0.4);">
            Зарегистрироваться бесплатно
        </a>
    </div>

    <div class="text-center" style="margin-top: 30px; padding-top: 30px; border-top: 1px solid #e2e8f0;">
        <p style="color: #718096; margin-bottom: 15px;">Следите за новыми вебинарами в личном кабинете</p>
        <?php
        $cabinet_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($cabinet_link); ?>" class="cta-button cta-button-secondary" style="display: inline-block; background: #ebebf0; color: #0077FF; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600;">
            Перейти в личный кабинет
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

<?php
/**
 * Email Template: Webinar Follow-up
 * Отправляется через 3 часа после начала - благодарность, запись, сертификат
 */

$email_subject = "Спасибо за участие в вебинаре! Запись и сертификат";

$utm = 'utm_source=email&utm_campaign=pismoposle1veba';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Спасибо за участие!</h1>
        <p>Вебинар завершён</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Благодарим вас за участие в вебинаре «<?php echo htmlspecialchars($webinar_title); ?>»!</p>

    <p>Надеемся, что материал был полезным и вы узнали много нового для своей профессиональной деятельности.</p>

    <div class="info-block" style="background: #FDF6E3; border-radius: 12px; padding: 20px; margin: 20px 0; border-left: 4px solid #F4C430;">
        <p style="margin: 0; color: #92400e; font-size: 15px;"><strong>📹 Запись вебинара</strong> будет отправлена вам на почту в течение суток.</p>
    </div>

    <!-- Блок: Презентация и подарок -->
    <div class="webinar-card" style="background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 100%); border-left: 4px solid #22c55e; border-radius: 16px; padding: 25px; margin: 25px 0;">
        <span class="badge" style="display:inline-block; background: #dcfce7; color: #16a34a; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 10px;">Бонус для участников</span>
        <h3 style="margin: 0 0 15px 0; color: #16a34a; font-size: 18px; font-weight: 600;">🎁 Презентация и подарок</h3>
        <p style="color: #4A5568; margin-bottom: 20px;">Скачайте презентацию вебинара и специальный подарок от спикера.</p>
        <a href="https://clck.ru/3SGVsi" style="display: inline-block; background: linear-gradient(135deg, #22c55e 0%, #16a34a 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(34, 197, 94, 0.4);">
            Скачать материалы
        </a>
    </div>

    <!-- Блок: Анкета обратной связи -->
    <div style="background: linear-gradient(135deg, #eff6ff 0%, #f8fafc 100%); border-radius: 16px; padding: 25px; margin: 25px 0; text-align: center; border: 2px dashed #93c5fd;">
        <h3 style="margin: 0 0 10px 0; color: #1e40af; font-size: 18px; font-weight: 600;">📝 Поделитесь впечатлениями</h3>
        <p style="color: #4A5568; margin-bottom: 20px;">Ваше мнение очень важно для нас! Заполните короткую анкету — это займёт не больше 2 минут.</p>
        <a href="https://clck.ru/3Rktcu" style="display: inline-block; background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(59, 130, 246, 0.4);">
            Заполнить анкету
        </a>
    </div>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>🏆 Получите сертификат участника</h3>
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

    <!-- Блок: Приглашение на следующий вебинар -->
    <div style="background: linear-gradient(135deg, #faf5ff 0%, #f3e8ff 100%); border-radius: 16px; padding: 25px; margin: 30px 0; text-align: center; border-left: 4px solid #8b5cf6;">
        <span class="badge" style="display:inline-block; background: #ede9fe; color: #7c3aed; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin-bottom: 10px;">Следующий вебинар</span>
        <h3 style="margin: 0 0 10px 0; color: #7c3aed; font-size: 18px; font-weight: 600;">📅 Приглашаем на следующий вебинар</h3>
        <p style="color: #4A5568; margin-bottom: 5px; font-weight: 600;">«Наставник 2026: новые подходы к сопровождению молодых педагогов»</p>
        <p style="color: #718096; margin-bottom: 20px; font-size: 14px;">19 марта 2026 в 14:00 МСК. Продолжите повышение квалификации вместе с нами!</p>
        <a href="https://fgos.pro/vebinar/nastavnik-2026?<?php echo $utm; ?>" style="display: inline-block; background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(139, 92, 246, 0.4);">
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

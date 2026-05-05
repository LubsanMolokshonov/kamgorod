<?php
/**
 * Email Template: Autowebinar Payment Reminder (3 days after order)
 * Через 3 дня после заказа сертификата, если не оплачен. Финальное.
 */

$email_subject = "Не упустите свой сертификат! -- {$webinar_title}";

$utm = 'utm_source=email&utm_campaign=aw-pay-3d';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Последнее напоминание</h1>
        <p>Сертификат ждёт оплаты</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Это последнее напоминание: ваш сертификат по вебинару <strong>«<?php echo htmlspecialchars($webinar_title); ?>»</strong> всё ещё ожидает оплаты.</p>

    <p>Вы успешно прошли тест и оформили заявку на сертификат. Осталось только завершить оплату, чтобы получить документ.</p>

    <div class="certificate-card">
        <span class="badge" style="background: rgba(255,255,255,0.5); color: #92400e;">Именной документ</span>
        <h3>Сертификат на <?php echo $certificate_hours; ?> академических часа</h3>
        <div class="price"><?php echo number_format($certificate_price, 0, '', ' '); ?> <small>руб.</small></div>
        <p style="color: #78716c; font-size: 14px; margin-bottom: 20px;">
            Для аттестации и профессионального портфолио
        </p>
        <?php
        $aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
        ?>
        <a href="<?php echo htmlspecialchars($aw_link); ?>" class="cta-button" style="display: inline-block; background: linear-gradient(135deg, #EF4444 0%, #DC2626 100%); color: #ffffff; text-decoration: none; padding: 18px 50px; border-radius: 50px; font-size: 16px; font-weight: 600; box-shadow: 0 4px 14px rgba(239, 68, 68, 0.4);">
            Оплатить и получить сертификат
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

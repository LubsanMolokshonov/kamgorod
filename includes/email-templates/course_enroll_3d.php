<?php
/**
 * Письмо 5: Через 3 дня — последний день скидки 10%
 * Переменные: $user_name, $course_title, $course_price, $discount_price,
 *             $course_hours, $program_label, $document_label, $course_program_type,
 *             $discount_url, $payment_url, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-enroll-3d';
$formattedPrice = number_format($course_price, 0, ',', ' ');
$formattedDiscount = number_format($discount_price, 0, ',', ' ');
$ctaUrl = $discount_url ?: $payment_url;

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Последний день скидки 10%</h1>
        <p>Сегодня — финальная возможность сэкономить</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Это последнее напоминание: ваша <strong>скидка 10%</strong> на курс истекает сегодня. После этого стоимость вернётся к полной цене.</p>

    <!-- Срочность -->
    <div style="background: #fef2f2; border: 2px solid #fca5a5; border-radius: 12px; padding: 20px; text-align: center; margin: 25px 0;">
        <div style="font-size: 28px; margin-bottom: 8px;">&#9203;</div>
        <strong style="font-size: 18px; color: #dc2626;">Скидка истекает сегодня!</strong>
        <p style="margin: 8px 0 0; font-size: 14px; color: #991b1b;">Успейте оплатить по выгодной цене</p>
    </div>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём:</strong> <?php echo (int)$course_hours; ?> часов &nbsp;|&nbsp; <strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
        <div style="margin-top: 15px;">
            <span style="text-decoration: line-through; color: #94a3b8; font-size: 16px;"><?php echo $formattedPrice; ?> &#8381;</span>
            <span style="font-size: 24px; font-weight: 700; color: #dc2626; margin-left: 10px;"><?php echo $formattedDiscount; ?> &#8381;</span>
            <div style="margin-top: 4px; font-size: 13px; color: #16a34a; font-weight: 600;">Вы экономите <?php echo number_format($course_price - $discount_price, 0, ',', ' '); ?> &#8381;</div>
        </div>
    </div>

    <div class="text-center">
        <?php $cta_link = $ctaUrl . (strpos($ctaUrl, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($cta_link); ?>" class="cta-button" style="background: linear-gradient(135deg, #dc2626 0%, #b91c1c 100%); font-size: 18px; padding: 18px 40px;">
            Оплатить сейчас со скидкой
        </a>
    </div>

    <ul class="benefits-list" style="margin-top: 30px;">
        <li>Участник проекта «Сколково» — разрешение № 068</li>
        <li>Документ вносится в ФИС ФРДО — примут при любой проверке</li>
        <li>Дистанционное обучение без отрыва от работы</li>
        <li>Более 28 000 педагогов уже прошли обучение</li>
    </ul>

    <div class="text-center" style="margin-top: 25px;">
        <a href="<?php echo htmlspecialchars($cta_link); ?>" class="cta-button cta-button-green">
            Воспользоваться скидкой 10%
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас остались вопросы, просто ответьте на это письмо — мы поможем!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

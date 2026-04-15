<?php
/**
 * Письмо 4: Через 2 дня — скидка 10% ещё действует
 * Переменные: $user_name, $course_title, $course_price, $discount_price,
 *             $course_hours, $program_label, $document_label, $course_program_type,
 *             $discount_url, $payment_url, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-enroll-2d';
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
        <h1>Ваша скидка 10% ещё действует</h1>
        <p>Не упустите выгодные условия</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Напоминаем: ваша <strong>персональная скидка 10%</strong> на курс «<?php echo htmlspecialchars($course_title); ?>» ещё активна. Тысячи педагогов уже повышают квалификацию вместе с нами.</p>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём:</strong> <?php echo (int)$course_hours; ?> часов &nbsp;|&nbsp; <strong>Формат:</strong> Дистанционно</p>
        </div>
        <div style="margin-top: 15px;">
            <span style="text-decoration: line-through; color: #94a3b8; font-size: 16px;"><?php echo $formattedPrice; ?> &#8381;</span>
            <span style="font-size: 24px; font-weight: 700; color: #16a34a; margin-left: 10px;"><?php echo $formattedDiscount; ?> &#8381;</span>
        </div>
    </div>

    <div class="text-center">
        <?php $cta_link = $ctaUrl . (strpos($ctaUrl, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($cta_link); ?>" class="cta-button cta-button-green">
            Воспользоваться скидкой
        </a>
    </div>

    <h3 style="color: #1e40af; margin-top: 30px; font-weight: 600;">Почему педагоги выбирают нас:</h3>

    <ul class="benefits-list">
        <li>Более 28 000 педагогов уже прошли обучение на нашей платформе</li>
        <li>Лицензия на образовательную деятельность + разрешение Фонда «Сколково»</li>
        <li>Данные о <?php echo $course_program_type === 'pp' ? 'дипломе' : 'удостоверении'; ?> вносятся в ФИС ФРДО</li>
        <li>Удобный формат — учитесь в любое время, из любого места</li>
    </ul>

    <div class="urgency-banner" style="margin-top: 25px; background: #fffbeb; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px;">
        <strong style="color: #92400e;">Осталось менее 24 часов</strong>
        <p style="margin: 4px 0 0; font-size: 14px; color: #78350f;">После истечения срока скидка будет отменена, и стоимость вернётся к <?php echo $formattedPrice; ?> &#8381;</p>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Есть вопросы? Ответьте на это письмо — мы свяжемся с вами.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

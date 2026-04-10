<?php
/**
 * Письмо 3: Через 24 часа — скидка 10%
 * Переменные: $user_name, $course_title, $course_price, $discount_price,
 *             $course_hours, $program_label, $document_label, $course_program_type,
 *             $discount_url, $payment_url, $course_url, $unsubscribe_url, $site_url
 */
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
        <h1>Специально для вас — скидка 10%!</h1>
        <p>Мы сохранили для вас особые условия</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вчера вы подали заявку на курс, но не завершили оплату. Мы подготовили для вас <strong>персональную скидку 10%</strong> — воспользуйтесь ею в ближайшие 48 часов!</p>

    <!-- Промо-баннер -->
    <div class="promo-banner" style="margin: 25px 0;">
        <h2 style="margin: 0 0 8px;">Скидка 10% на курс</h2>
        <p style="margin: 0; opacity: 0.9;">Только для вас. Действует 48 часов.</p>
    </div>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Формат:</strong> Заочная с применением ДОТ</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
        <div style="margin-top: 15px;">
            <span style="text-decoration: line-through; color: #94a3b8; font-size: 16px;"><?php echo $formattedPrice; ?> &#8381;</span>
            <span style="font-size: 24px; font-weight: 700; color: #16a34a; margin-left: 10px;"><?php echo $formattedDiscount; ?> &#8381;</span>
        </div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($ctaUrl); ?>" class="cta-button cta-button-green">
            Оплатить со скидкой 10%
        </a>
    </div>

    <ul class="benefits-list" style="margin-top: 30px;">
        <li>Участник проекта «Сколково» — разрешение № 068</li>
        <li><?php echo htmlspecialchars($document_label); ?> вносится в ФИС ФРДО</li>
        <li>Удобное дистанционное обучение в своём темпе</li>
        <li>Документ примут при аттестации и проверке Рособрнадзора</li>
    </ul>

    <div class="urgency-banner" style="margin-top: 25px; background: #fffbeb; border-left: 4px solid #f59e0b; padding: 16px 20px; border-radius: 8px;">
        <strong style="color: #92400e;">Скидка действует 48 часов</strong>
        <p style="margin: 4px 0 0; font-size: 14px; color: #78350f;">После истечения срока стоимость вернётся к <?php echo $formattedPrice; ?> &#8381;</p>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникли вопросы, просто ответьте на это письмо — мы с радостью поможем!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

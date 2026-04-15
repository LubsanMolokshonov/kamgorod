<?php
/**
 * Письмо 0: Подтверждение записи на курс (сразу)
 * Переменные: $user_name, $course_title, $course_price, $course_hours,
 *             $program_label, $document_label, $course_program_type,
 *             $payment_url, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-enroll-welcome';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Заявка на курс принята!</h1>
        <p>Осталось завершить оплату, чтобы начать обучение</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Мы получили вашу заявку на курс. Для начала обучения необходимо завершить оплату в личном кабинете.</p>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Формат:</strong> Заочная с применением ДОТ</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
        <div class="price-tag"><?php echo number_format($course_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button">
            Перейти к оплате
        </a>
    </div>

    <ul class="benefits-list" style="margin-top: 30px;">
        <li>ООО «Едурегионлаб» — участник проекта «Сколково»</li>
        <li>Разрешение Фонда «Сколково» № 068 на образовательную деятельность</li>
        <li><?php echo htmlspecialchars($document_label); ?> вносится в ФИС ФРДО</li>
        <li>Заочное обучение с применением дистанционных образовательных технологий</li>
    </ul>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникли вопросы, просто ответьте на это письмо — мы с радостью поможем!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

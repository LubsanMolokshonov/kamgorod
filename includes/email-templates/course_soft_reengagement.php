<?php
/**
 * Мягкое реактивационное письмо для заявок со сделкой «в работе» (status=enrolled).
 * БЕЗ цены и БЕЗ скидки — чтобы не подрезать менеджера, который ведёт сделку.
 * Переменные: $user_name, $course_title, $program_label, $document_label,
 *             $course_hours, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-soft-reengagement';
$course_link = $course_url . (strpos($course_url, '?') !== false ? '&' : '?') . $utm;
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Остались вопросы по курсу?</h1>
        <p>Мы рядом и поможем с любым шагом</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы оставляли заявку на курс — хотим убедиться, что у вас всё в порядке и не осталось открытых вопросов.</p>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Формат:</strong> Заочная с применением ДОТ</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
    </div>

    <p>Если нужно подобрать удобный график, уточнить детали программы или помочь с оформлением — просто <strong>ответьте на это письмо</strong>, и мы всё подскажем.</p>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($course_link); ?>" class="cta-button cta-button-outline">
            Открыть страницу курса
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Мы на связи в будни и стараемся отвечать в течение рабочего дня. Будем рады помочь!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

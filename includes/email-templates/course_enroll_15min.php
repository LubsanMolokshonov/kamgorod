<?php
/**
 * Письмо 1: Напоминание через 15 минут
 * Переменные: $user_name, $course_title, $course_price, $course_hours,
 *             $program_label, $document_label, $course_program_type,
 *             $payment_url, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-enroll-15min';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Ваше место на курсе забронировано!</h1>
        <p>Завершите оплату, чтобы приступить к обучению</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы записались на курс <strong>«<?php echo htmlspecialchars($course_title); ?>»</strong>. Ваша заявка сохранена — осталось только завершить оплату.</p>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
        <div class="price-tag"><?php echo number_format($course_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <h3 style="color: #1e40af; margin-top: 25px; font-weight: 600;">Что даёт <?php echo $course_program_type === 'pp' ? 'диплом' : 'удостоверение'; ?>:</h3>

    <ul class="benefits-list">
        <li>Соответствие требованиям аттестации и проверок Рособрнадзора</li>
        <li>Подтверждение профессиональной компетенции перед работодателем</li>
        <li>Запись в Федеральном реестре сведений о документах об образовании (ФИС ФРДО)</li>
        <li>Основание для карьерного роста и надбавок к зарплате</li>
    </ul>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button">
            Оплатить курс
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникли вопросы по программе или оплате, просто ответьте на это письмо.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

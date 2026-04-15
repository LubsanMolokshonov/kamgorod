<?php
/**
 * Письмо 2: Напоминание через 1 час
 * Переменные: $user_name, $course_title, $course_price, $course_hours,
 *             $program_label, $document_label, $course_program_type,
 *             $payment_url, $course_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=course-enroll-1h';
ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Не откладывайте профессиональный рост</h1>
        <p>Изменения в законодательстве уже действуют</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы подали заявку на курс <strong>«<?php echo htmlspecialchars($course_title); ?>»</strong>, но ещё не завершили оплату. Напоминаем, почему важно не откладывать повышение квалификации.</p>

    <!-- Блок об изменении закона -->
    <div class="urgency-banner" style="margin-top: 25px;">
        <div style="font-size: 20px; margin-bottom: 8px;">&#9888;&#65039;</div>
        <strong style="font-size: 16px;">С 1 сентября 2025 года изменились правила повышения квалификации</strong>
        <p style="margin: 8px 0 0 0; font-size: 14px;">
            Федеральный закон от 21.04.2025 № 86-ФЗ — новая часть 5.2 статьи 47 Закона «Об образовании» (273-ФЗ)
        </p>
    </div>

    <!-- Два столбца: риски vs преимущества -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 25px 0;">
        <tr>
            <td valign="top" width="48%" style="padding-right: 2%;">
                <div style="background: #fef2f2; border-radius: 12px; padding: 20px;">
                    <p style="margin: 0 0 12px 0; font-weight: 700; color: #dc2626; font-size: 14px;">
                        Риски обучения в неуполномоченных организациях
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #7f1d1d;">
                        <span style="color: #dc2626; font-weight: bold;">&#10005;</span>&nbsp;&nbsp;Документ не примут при аттестации и проверке Рособрнадзора
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #7f1d1d;">
                        <span style="color: #dc2626; font-weight: bold;">&#10005;</span>&nbsp;&nbsp;Работодатель вправе не засчитать повышение квалификации
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #7f1d1d;">
                        <span style="color: #dc2626; font-weight: bold;">&#10005;</span>&nbsp;&nbsp;Потеря денег и времени — придётся переучиваться заново
                    </p>
                </div>
            </td>
            <td valign="top" width="48%" style="padding-left: 2%;">
                <div style="background: #f0fdf4; border-radius: 12px; padding: 20px;">
                    <p style="margin: 0 0 12px 0; font-weight: 700; color: #16a34a; font-size: 14px;">
                        Почему «ФГОС-практикум» — надёжный выбор
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #14532d;">
                        <span style="color: #16a34a; font-weight: bold;">&#10003;</span>&nbsp;&nbsp;Разрешение Фонда «Сколково» № 068 на образовательную деятельность
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #14532d;">
                        <span style="color: #16a34a; font-weight: bold;">&#10003;</span>&nbsp;&nbsp;Все данные вносятся в ФИС ФРДО в течение 30 дней
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #14532d;">
                        <span style="color: #16a34a; font-weight: bold;">&#10003;</span>&nbsp;&nbsp;<?php echo htmlspecialchars($document_label); ?> — примут при любой проверке
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём:</strong> <?php echo (int)$course_hours; ?> часов &nbsp;|&nbsp; <strong>Формат:</strong> Дистанционно</p>
        </div>
        <div class="price-tag"><?php echo number_format($course_price, 0, ',', ' '); ?> &#8381;</div>
    </div>

    <div class="text-center">
        <?php $pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($pay_link); ?>" class="cta-button">
            Записаться на обучение
        </a>
    </div>

    <p style="margin-top: 30px; font-size: 11px; color: #94a3b8; line-height: 1.5;">
        Основание: ч. 5.2 ст. 47 Федерального закона от 29.12.2012 № 273-ФЗ «Об образовании в РФ»
        (в ред. ФЗ от 21.04.2025 № 86-ФЗ), Постановление Правительства РФ № 850.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

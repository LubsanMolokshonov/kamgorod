<?php
/**
 * Промо-шаблон: персонализированная рекомендация курса
 * Переменные: $user_name, $course_title, $course_description, $course_hours,
 *             $course_price, $course_program_type, $course_url,
 *             $unsubscribe_url, $site_url, $site_name, $footer_reason
 */

$email_subject = ($course_program_type === 'pp' ? 'Профессиональная переподготовка' : 'Повышение квалификации')
    . ': ' . mb_substr($course_title, 0, 60);

$programLabel = $course_program_type === 'pp'
    ? 'Профессиональная переподготовка'
    : 'Повышение квалификации';

$formattedPrice = number_format($course_price, 0, ',', ' ');

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo htmlspecialchars($site_url); ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Повысьте квалификацию с&nbsp;удостоверением государственного образца</h1>
        <p>Дистанционное обучение от участника проекта «Сколково»</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Мы подобрали для вас курс, который поможет подтвердить и повысить вашу квалификацию в соответствии с актуальными требованиями законодательства.</p>

    <!-- Карточка курса -->
    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($programLabel); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <?php if (!empty($course_description)): ?>
        <p style="color: #475569; font-size: 14px; margin: 10px 0;"><?php echo htmlspecialchars(mb_substr($course_description, 0, 200)); ?></p>
        <?php endif; ?>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Формат:</strong> Заочная с применением ДОТ</p>
            <p><strong>Документ:</strong> <?php echo $course_program_type === 'pp' ? 'Диплом о профессиональной переподготовке' : 'Удостоверение о повышении квалификации'; ?></p>
        </div>
        <div class="price-tag"><?php echo $formattedPrice; ?> &#8381;</div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($course_url); ?>?utm_source=email&utm_medium=promo&utm_campaign=course_promo" class="cta-button">
            Записаться на курс
        </a>
    </div>

    <!-- Блок об изменении закона -->
    <div class="urgency-banner" style="margin-top: 35px;">
        <div style="font-size: 20px; margin-bottom: 8px;">&#9888;&#65039;</div>
        <strong style="font-size: 16px;">С 1 сентября 2025 года изменились правила повышения квалификации</strong>
        <p style="margin: 8px 0 0 0; font-size: 14px;">
            Федеральный закон от 21.04.2025 № 86-ФЗ — новая часть 5.2 статьи 47 Закона «Об образовании» (273-ФЗ)
        </p>
    </div>

    <!-- Два столбца: Риски vs Преимущества -->
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
                        <span style="color: #dc2626; font-weight: bold;">&#10005;</span>&nbsp;&nbsp;Запись в ФИС ФРДО не подтверждает право организации обучать педагогов
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
                        <span style="color: #16a34a; font-weight: bold;">&#10003;</span>&nbsp;&nbsp;Удостоверение установленного образца — примут при любой проверке
                    </p>
                    <p style="margin: 8px 0; font-size: 13px; color: #14532d;">
                        <span style="color: #16a34a; font-weight: bold;">&#10003;</span>&nbsp;&nbsp;Действующая лицензия на образовательную деятельность
                    </p>
                </div>
            </td>
        </tr>
    </table>

    <!-- Преимущества списком -->
    <ul class="benefits-list">
        <li>ООО «Едурегионлаб» — участник проекта «Сколково»</li>
        <li>Разрешение Фонда «Сколково» № 068 на образовательную деятельность</li>
        <li>Удостоверение/диплом вносится в ФИС ФРДО</li>
        <li>Заочное обучение с применением дистанционных образовательных технологий</li>
    </ul>

    <!-- Повторная CTA -->
    <div class="text-center" style="margin-top: 30px;">
        <a href="<?php echo htmlspecialchars($course_url); ?>?utm_source=email&utm_medium=promo&utm_campaign=course_promo" class="cta-button cta-button-green">
            Записаться на курс
        </a>
    </div>

    <!-- Основание -->
    <p style="margin-top: 30px; font-size: 11px; color: #94a3b8; line-height: 1.5;">
        Основание: ч. 5.2 ст. 47 Федерального закона от 29.12.2012 № 273-ФЗ «Об образовании в РФ»
        (в ред. ФЗ от 21.04.2025 № 86-ФЗ), Постановление Правительства РФ № 850.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

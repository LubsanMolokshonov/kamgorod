<?php
/**
 * Письмо: Заявка на рассрочку курса принята.
 * Отправляется из ajax/request-course-installment.php → CourseEmailChain::sendInstallmentRequestedConfirmation.
 *
 * Переменные: $user_name, $course_title, $course_hours, $course_program_type,
 *             $program_label, $document_label, $monthly_payment, $months,
 *             $cabinet_url, $course_url, $unsubscribe_url, $site_url, $site_name
 */
$utm = 'utm_source=email&utm_campaign=course-installment-requested';
$formattedMonthly = number_format((float)$monthly_payment, 0, ',', ' ');
$maxUrl = defined('MAX_MANAGER_URL') ? MAX_MANAGER_URL : 'https://max.ru/u/f9LHodD0cOJKXZhXUQImrGumTp40Eiu4o40RTZGhnpMVWgNe6tGt0x0OSco';
$maxPhone = defined('MAX_MANAGER_PHONE') ? MAX_MANAGER_PHONE : '+7 922 304 44 13';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Заявка на рассрочку принята</h1>
        <p>Менеджер свяжется с вами в рабочее время</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Мы получили вашу заявку на рассрочку 0% по курсу. Менеджер свяжется с вами в рабочее время, чтобы согласовать график платежей и оформить договор.</p>

    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 24px; text-align: center; margin: 25px 0;">
        <div style="font-size: 36px; margin-bottom: 8px;">&#10003;</div>
        <strong style="font-size: 18px; color: #16a34a;">Заявка зарегистрирована</strong>
        <p style="margin: 8px 0 0; font-size: 14px; color: #15803d;">
            Ориентировочно ~<?php echo $formattedMonthly; ?> &#8381;/мес &times; <?php echo (int)$months; ?>
        </p>
    </div>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
    </div>

    <!-- Max CTA: ускоряет согласование рассрочки -->
    <div style="background: linear-gradient(135deg, #fff4e6 0%, #ffe8d6 100%); border: 1px solid #ffb86b; border-radius: 12px; padding: 22px; margin: 28px 0;">
        <h3 style="margin: 0 0 8px; font-size: 17px; color: #2a1a06;">Хотите ускорить оформление?</h3>
        <p style="margin: 0 0 16px; font-size: 14px; line-height: 1.5; color: #4a3520;">
            Напишите менеджеру в Messenger Max — так заявку рассмотрят быстрее, чем по очереди звонков.
        </p>
        <div style="text-align: center;">
            <a href="<?php echo htmlspecialchars($maxUrl); ?>"
               style="display: inline-block; padding: 12px 28px; background: #ff8c32; color: #fff; border-radius: 8px; font-weight: 600; font-size: 15px; text-decoration: none;">
                Написать в Max
            </a>
            <p style="margin: 10px 0 0; font-size: 13px; color: #6b5638;">
                или по номеру: <strong style="color: #2a1a06;"><?php echo htmlspecialchars($maxPhone); ?></strong>
            </p>
        </div>
    </div>

    <h3 style="color: #182f8a; margin-top: 30px; font-weight: 600;">Что будет дальше:</h3>
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
        <tr>
            <td style="padding: 12px 16px; vertical-align: top; width: 44px;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">1</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Согласование графика</strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;">Менеджер уточнит срок и размер платежей, поможет выбрать удобный банк-партнёр.</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; vertical-align: top;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">2</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Оформление договора</strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;">Подпишем договор и обеспечим доступ к учебным материалам.</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; vertical-align: top;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">3</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Начало обучения</strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;">Изучаете программу дистанционно, по итогам — <?php echo htmlspecialchars($document_label); ?>.</p>
            </td>
        </tr>
    </table>

    <div class="text-center" style="margin-top: 25px;">
        <?php $cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm; ?>
        <a href="<?php echo htmlspecialchars($cab_link); ?>" class="cta-button">
            Перейти в личный кабинет
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникнут вопросы, просто ответьте на это письмо — мы всегда рады помочь!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

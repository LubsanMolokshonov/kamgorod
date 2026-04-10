<?php
/**
 * Письмо: Подтверждение оплаты курса
 * Отправляется сразу после успешной оплаты через Yookassa webhook.
 *
 * Переменные: $user_name, $course_title, $course_price, $course_hours,
 *             $program_label, $document_label, $course_program_type,
 *             $cabinet_url, $course_url, $unsubscribe_url, $site_url,
 *             $order_number
 */
$formattedPrice = number_format($course_price, 0, ',', ' ');

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Оплата прошла успешно!</h1>
        <p>Добро пожаловать на курс</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Благодарим вас за оплату! Ваш доступ к курсу активирован. Ниже — детали вашего заказа.</p>

    <!-- Подтверждение -->
    <div style="background: #f0fdf4; border: 2px solid #86efac; border-radius: 12px; padding: 24px; text-align: center; margin: 25px 0;">
        <div style="font-size: 36px; margin-bottom: 8px;">&#10003;</div>
        <strong style="font-size: 18px; color: #16a34a;">Оплата подтверждена</strong>
        <p style="margin: 8px 0 0; font-size: 14px; color: #15803d;">Заказ <?php echo htmlspecialchars($order_number); ?> &bull; <?php echo $formattedPrice; ?> &#8381;</p>
    </div>

    <div class="competition-card">
        <span class="badge"><?php echo htmlspecialchars($program_label); ?></span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов</p>
            <p><strong>Формат:</strong> Заочная с применением ДОТ</p>
            <p><strong>Документ:</strong> <?php echo htmlspecialchars($document_label); ?></p>
        </div>
    </div>

    <h3 style="color: #1e40af; margin-top: 30px; font-weight: 600;">Что будет дальше:</h3>

    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
        <tr>
            <td style="padding: 12px 16px; vertical-align: top; width: 44px;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">1</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Доступ к учебным материалам</strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;">Наш методист свяжется с вами для организации доступа к учебным материалам курса</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; vertical-align: top;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">2</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Обучение в удобном темпе</strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;">Изучайте материалы дистанционно, в своём графике — без отрыва от работы</p>
            </td>
        </tr>
        <tr>
            <td style="padding: 12px 16px; vertical-align: top;">
                <div style="background: #667eea; color: white; width: 32px; height: 32px; border-radius: 50%; text-align: center; line-height: 32px; font-weight: 700; font-size: 16px;">3</div>
            </td>
            <td style="padding: 12px 0; vertical-align: top;">
                <strong style="color: #1e293b;">Получение <?php echo $course_program_type === 'pp' ? 'диплома' : 'удостоверения'; ?></strong>
                <p style="margin: 4px 0 0; font-size: 14px; color: #64748b;"><?php echo htmlspecialchars($document_label); ?> с внесением данных в ФИС ФРДО в течение 30 дней</p>
            </td>
        </tr>
    </table>

    <div class="text-center" style="margin-top: 25px;">
        <a href="<?php echo htmlspecialchars($cabinet_url); ?>" class="cta-button">
            Перейти в личный кабинет
        </a>
    </div>

    <ul class="benefits-list" style="margin-top: 30px;">
        <li>ООО «Едурегионлаб» — участник проекта «Сколково»</li>
        <li>Разрешение Фонда «Сколково» № 068 на образовательную деятельность</li>
        <li>Данные о документе вносятся в ФИС ФРДО</li>
        <li>Документ примут при аттестации и проверке Рособрнадзора</li>
    </ul>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникнут вопросы, просто ответьте на это письмо — мы всегда рады помочь!
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

<?php
/**
 * Промо-письмо сегменту «воспитатели»: курс переподготовки + персональная скидка 10%.
 * Кампания vospitateli_pp10_jul2026 (scripts/send_vospitateli_pp_invitation.php).
 *
 * Переменные: $user_name, $course_title, $course_hours, $course_url,
 *             $price_current (витринная цена), $price_discounted (со скидкой кампании),
 *             $discount_percent, $discount_deadline_label ("21 июля"),
 *             $sender_signature, $unsubscribe_url, $site_url, $footer_reason
 */

$fmtCurrent    = number_format($price_current, 0, ',', ' ');
$fmtDiscounted = number_format($price_discounted, 0, ',', ' ');

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo htmlspecialchars($site_url); ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Диплом о профессиональной переподготовке для воспитателя</h1>
        <p>Дистанционное обучение от участника проекта «Сколково»</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>!</p>

    <p>Вы указали при регистрации, что работаете в дошкольном образовании, поэтому пишем именно вам.
    По профстандарту «Педагог» воспитателю ДОУ нужна профильная дошкольная подготовка — при аттестации
    и проверках Рособрнадзора её подтверждает диплом о переподготовке.</p>

    <p>До <?php echo htmlspecialchars($discount_deadline_label); ?> включительно для вас действует
    персональная скидка <?php echo (int)$discount_percent; ?>% на программу переподготовки.
    Скидка уже закреплена за вашим личным кабинетом — промокод не нужен, цена пересчитается сама при оплате.</p>

    <!-- Карточка курса -->
    <div class="competition-card">
        <span class="badge">Профессиональная переподготовка</span>
        <h3><?php echo htmlspecialchars($course_title); ?></h3>
        <div class="competition-details">
            <p><strong>Объём программы:</strong> <?php echo (int)$course_hours; ?> часов — соответствует профстандарту «Педагог» (дошкольное образование)</p>
            <p><strong>Формат:</strong> заочная, с применением дистанционных технологий — без отрыва от работы</p>
            <p><strong>Документ:</strong> диплом о профессиональной переподготовке, вносится в ФИС ФРДО</p>
        </div>
        <div class="price-tag">
            <span style="text-decoration: line-through; color: #94a3b8; font-size: 0.8em;"><?php echo $fmtCurrent; ?> &#8381;</span>
            &nbsp;<?php echo $fmtDiscounted; ?> &#8381;
        </div>
        <p style="margin: 6px 0 0 0; font-size: 13px; color: #64748b;">
            Ваша цена со скидкой <?php echo (int)$discount_percent; ?>% — действует до <?php echo htmlspecialchars($discount_deadline_label); ?>
        </p>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($course_url); ?>" class="cta-button">
            Посмотреть программу курса
        </a>
    </div>

    <!-- Почему нам можно доверять -->
    <ul class="benefits-list" style="margin-top: 30px;">
        <li>ООО «Едурегионлаб» — участник проекта «Сколково», разрешение Фонда № 068 на образовательную деятельность</li>
        <li>Диплом вносится в ФИС ФРДО в течение 30 дней — его примут при аттестации и любой проверке</li>
        <li>Обучение полностью дистанционное: материалы открываются сразу, итоговая работа — онлайн</li>
        <li>Официальный договор и чек; возможна оплата в рассрочку</li>
    </ul>

    <p>Если удобнее сначала задать вопросы — просто ответьте на это письмо, поможем выбрать программу
    и расскажем про рассрочку.</p>

    <p style="margin-top: 25px;"><?php echo htmlspecialchars($sender_signature); ?></p>

    <!-- Основание -->
    <p style="margin-top: 30px; font-size: 11px; color: #94a3b8; line-height: 1.5;">
        Основание требований к квалификации: профстандарт «Педагог» (приказ Минтруда № 544н),
        ч. 5.2 ст. 47 Федерального закона от 29.12.2012 № 273-ФЗ «Об образовании в РФ»
        (в ред. ФЗ от 21.04.2025 № 86-ФЗ).
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

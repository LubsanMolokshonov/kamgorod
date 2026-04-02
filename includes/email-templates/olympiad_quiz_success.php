<?php
/**
 * Олимпиада: успешное прохождение теста (≥7 баллов)
 * Поздравление + призыв оформить диплом
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
ob_start();
?>
<div class="email-header" style="background: linear-gradient(135deg, #059669 0%, #047857 50%, #065f46 100%);">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Поздравляем! <?php echo htmlspecialchars($placement_text); ?>!</h1>
        <p>Вы блестяще прошли олимпиаду</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Отличные новости! Вы успешно прошли олимпиаду и показали высокий результат:</p>

    <div class="competition-card" style="border-left: 4px solid #059669;">
        <span class="badge" style="background: #059669;"><?php echo htmlspecialchars($placement_text); ?></span>
        <h3><?php echo htmlspecialchars($olympiad_title); ?></h3>
        <div class="competition-details">
            <p style="font-size: 24px; font-weight: bold; color: #059669; margin: 10px 0;"><?php echo intval($score); ?> из 10 баллов</p>
        </div>
    </div>

    <p>Теперь вы можете оформить <strong>официальный диплом олимпиады</strong> и подтвердить свой результат.</p>

    <h3 style="color: #1e40af; margin-top: 20px; font-weight: 600;">Ваш диплом будет содержать:</h3>

    <ul class="benefits-list">
        <li>ФИО участника и занятое место</li>
        <li>Название олимпиады и результат</li>
        <li>Официальные реквизиты организатора</li>
        <li>PDF высокого качества для печати</li>
    </ul>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($olympiad_url); ?>" class="cta-button" style="background: linear-gradient(135deg, #059669, #047857);">
            Оформить диплом
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Диплом будет доступен в личном кабинете сразу после оплаты.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

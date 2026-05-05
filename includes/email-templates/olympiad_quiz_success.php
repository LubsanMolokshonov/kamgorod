<?php
/**
 * Олимпиада: успешное прохождение теста (≥7 баллов)
 * Поздравление + призыв оформить диплом.
 * Содержит HTML-превью будущего диплома (не реальный рендер, а стилизованная карточка).
 */
$footer_reason = 'прошли олимпиаду на нашем портале';
$utm = 'utm_source=email&utm_campaign=olympiad-quiz-success';
$diploma_link = (!empty($diploma_url) ? $diploma_url : $olympiad_url)
    . (strpos((!empty($diploma_url) ? $diploma_url : $olympiad_url), '?') !== false ? '&' : '?') . $utm;
$today_ru = date('d.m.Y');
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

    <p>Отличные новости! Вы успешно прошли олимпиаду и заняли <strong><?php echo htmlspecialchars($placement_text); ?></strong> с результатом <strong><?php echo intval($score); ?> из 10 баллов</strong>.</p>

    <p style="margin-top: 20px; font-weight: 600; color: #1e40af;">Вот как будет выглядеть ваш диплом:</p>

    <!-- Превью диплома: HTML-карточка, имитирующая официальный документ -->
    <table width="100%" cellpadding="0" cellspacing="0" border="0" style="margin: 20px 0;">
        <tr>
            <td style="padding: 0;">
                <table width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #ffffff; border: 3px double #d4af37; border-radius: 8px;">
                    <tr>
                        <td style="padding: 28px 24px; text-align: center; font-family: Georgia, 'Times New Roman', serif;">
                            <div style="font-size: 12px; letter-spacing: 2px; color: #b8860b; text-transform: uppercase; margin-bottom: 10px;">Диплом олимпиады</div>
                            <div style="font-size: 22px; font-weight: 700; color: #7b5b00; margin-bottom: 14px;"><?php echo htmlspecialchars($placement_text); ?></div>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 6px;">награждается</div>
                            <div style="font-size: 18px; font-weight: 700; color: #111827; margin-bottom: 16px; line-height: 1.3;"><?php echo htmlspecialchars($user_name); ?></div>
                            <div style="font-size: 12px; color: #6b7280; margin-bottom: 4px;">за успешное прохождение олимпиады</div>
                            <div style="font-size: 14px; font-weight: 600; color: #1f2937; margin-bottom: 14px; line-height: 1.4;">«<?php echo htmlspecialchars($olympiad_title); ?>»</div>
                            <div style="font-size: 13px; color: #374151; margin-bottom: 14px;">Результат: <strong><?php echo intval($score); ?> из 10 баллов</strong></div>
                            <div style="border-top: 1px solid #e5e7eb; padding-top: 10px; font-size: 11px; color: #9ca3af;">
                                Дата: <?php echo $today_ru; ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <p style="font-size: 12px; color: #94a3b8; text-align: center; margin: 8px 0 0;">Это предварительный вид — в дипломе будут официальные реквизиты и печать организатора.</p>
            </td>
        </tr>
    </table>

    <h3 style="color: #1e40af; margin-top: 24px; font-weight: 600;">Ваш диплом будет содержать:</h3>

    <ul class="benefits-list">
        <li>ФИО участника и занятое место</li>
        <li>Название олимпиады и результат</li>
        <li>Официальные реквизиты организатора</li>
        <li>PDF высокого качества для печати</li>
    </ul>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($diploma_link); ?>" class="cta-button" style="background: linear-gradient(135deg, #059669, #047857);">
            Оформить диплом за <?php echo intval($olympiad_price); ?> &#8381;
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Диплом будет доступен в личном кабинете сразу после оплаты.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

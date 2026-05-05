<?php
/**
 * Personal-style layout — для писем, которые иначе попадают в Gmail «Промоакции».
 *
 * Принципы:
 *  - Белый фон, без gradient header / promo-banner / urgency-banner.
 *  - Один sans-serif font, тёмно-серый текст, узкая колонка.
 *  - Нет «кнопок-CTA» — единственная ссылка inline-стилем (синий, подчёркивание).
 *  - Минимум inline-images (no logos), без эмодзи в шапке.
 *  - В подвале только обязательные поля (название организации, адрес, отписка).
 *
 * Параметры:
 *   $content          — HTML тела письма (через ob_start/ob_get_clean в шаблоне).
 *   $unsubscribe_url  — обязателен (требование Mail.ru / Yandex / Gmail для bulk).
 *   $footer_reason    — короткое объяснение, почему письмо пришло.
 *   $sender_signature — имя в подписи (по умолчанию «Анна, ФГОС-Практикум»).
 */
$site_url        = $site_url        ?? (defined('SITE_URL') ? SITE_URL : 'https://fgos.pro');
$footer_reason   = $footer_reason   ?? 'оставили заявку на портале fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';
$unsubscribe_url = $unsubscribe_url ?? ($site_url . '/pages/unsubscribe.php');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($email_subject ?? 'ФГОС-Практикум'); ?></title>
    <style>
        body { margin: 0; padding: 0; background: #ffffff; color: #222; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.55; }
        .wrap { max-width: 560px; margin: 0 auto; padding: 28px 20px 24px; }
        .body p { margin: 0 0 14px; }
        .body a { color: #1a56db; }
        .signature { margin-top: 22px; color: #222; }
        .footer { margin-top: 28px; padding-top: 14px; border-top: 1px solid #e5e7eb; color: #6b7280; font-size: 12px; line-height: 1.5; }
        .footer a { color: #6b7280; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="body">
            <?php echo $content; ?>

            <div class="signature">
                — <?php echo htmlspecialchars($sender_signature); ?>
            </div>
        </div>

        <div class="footer">
            <?php echo htmlspecialchars($footer_reason); ?>.
            Если рассылка вам больше не нужна — <a href="<?php echo htmlspecialchars($unsubscribe_url); ?>">отписаться</a>.<br>
            ООО «Едурегионлаб», ИНН 5904368615, г. Москва, тер. Инновационного центра Сколково, б-р Большой, д. 42, стр. 1.
        </div>
    </div>
</body>
</html>

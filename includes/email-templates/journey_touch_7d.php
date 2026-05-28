<?php
/**
 * Touch 4: 7 Days After Registration — личный тон, без promo-баннеров.
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=competition-touch-7d';
$footer_reason = $footer_reason ?? 'оставили заявку на участие в конкурсе на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm;
$catalog_link = $site_url . '/konkursy/?' . $utm;
$discountPercent = !empty($discount_rate) ? (int)round($discount_rate * 100) : 0;
$discountDays = !empty($discount_hours) ? (int)round($discount_hours / 24) : 0;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Неделю назад вы оставили заявку на конкурс «<?php echo htmlspecialchars($competition_title); ?>», но не завершили оформление. Хотел уточнить — вам всё ещё нужен диплом? Если да, ниже ссылка, чтобы дооформить заявку.</p>

<?php if ($discountPercent > 0): ?>
<p>Это последнее напоминание по этой заявке, поэтому оставляю персональную скидку <strong><?php echo $discountPercent; ?>%</strong><?php if ($discountDays > 0): ?> — она действует ещё <?php echo $discountDays; ?> дня<?php endif; ?> и применится в корзине сама.</p>
<?php endif; ?>

<p><a href="<?php echo htmlspecialchars($pay_link); ?>">Дооформить заявку на конкурс</a><?php if ($discountPercent > 0): ?> со скидкой<?php endif; ?></p>

<p>Если интересны другие конкурсы — посмотрите <a href="<?php echo htmlspecialchars($catalog_link); ?>">текущий каталог</a>. Если нет — ничего делать не нужно, мы больше не будем напоминать про эту заявку.</p>

<p>Если возник вопрос — просто ответьте на это письмо.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

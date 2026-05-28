<?php
/**
 * Touch 3: 3 дня после регистрации — личный тон, _personal_layout.
 * При наличии $discount_rate показываем персональную скидку
 * (создаётся в EmailJourney через EmailCampaignDiscount::upsert, применяется в корзине).
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=competition-touch-3d';
$footer_reason = $footer_reason ?? 'оставили заявку на участие в конкурсе на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm;
$discountPercent = !empty($discount_rate) ? (int)round($discount_rate * 100) : 0;
$discountDays = !empty($discount_hours) ? (int)round($discount_hours / 24) : 0;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Несколько дней назад вы оставили заявку на конкурс «<?php echo htmlspecialchars($competition_title); ?>», но не завершили оформление. Заявка ещё активна.</p>

<?php if ($discountPercent > 0): ?>
<p>Чтобы было проще решиться, я закрепила за вами персональную скидку <strong><?php echo $discountPercent; ?>%</strong><?php if ($discountDays > 0): ?> на ближайшие <?php echo $discountDays; ?> дня<?php endif; ?> — она применится в корзине автоматически.</p>
<?php endif; ?>

<p><a href="<?php echo htmlspecialchars($pay_link); ?>">Дооформить заявку на конкурс</a><?php if ($discountPercent > 0): ?> со скидкой<?php endif; ?>.</p>

<p>Диплом приходит в личный кабинет сразу после оплаты. Если вопрос отпал — ничего делать не нужно, либо просто ответьте на это письмо.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

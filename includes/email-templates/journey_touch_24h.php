<?php
/**
 * Touch 2: 24 часа после регистрации — личный тон, _personal_layout.
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=competition-touch-24h';
$footer_reason = $footer_reason ?? 'оставили заявку на участие в конкурсе на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Вчера вы оставили заявку на конкурс «<?php echo htmlspecialchars($competition_title); ?>», но не завершили оплату. Хотела уточнить — вам всё ещё нужен диплом?</p>

<p>Если да — диплом будет в личном кабинете сразу после оплаты, в PDF высокого качества, его можно скачивать и распечатывать сколько угодно раз. Это готовая строка в портфолио к аттестации.</p>

<p><a href="<?php echo htmlspecialchars($pay_link); ?>">Дооформить заявку на конкурс</a> — <?php echo number_format($competition_price, 0, ',', ' '); ?> ₽.</p>

<p>Если вопрос отпал — ничего делать не нужно. А если что-то осталось неясным, просто ответьте на это письмо.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

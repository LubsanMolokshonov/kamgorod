<?php
/**
 * Письмо: второе касание recovery после неудачной оплаты.
 * Отправляется PaymentRecoveryChain::processSecondTouch() через ~48ч после первого,
 * если заказ всё ещё failed и пользователь не оплатил. Личный тон, _personal_layout.
 *
 * Переменные: $user_name, $order_number, $final_amount, $items[],
 *             $recovery_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_medium=transactional&utm_campaign=payment_recovery_2';
$footer_reason = 'у вас остался неоплаченный заказ на портале fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$formattedAmount = number_format($final_amount, 0, ',', ' ');
$recoveryUrlWithUtm = $recovery_url . (strpos($recovery_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Пару дней назад оплата по вашему заказу <?php echo htmlspecialchars($order_number); ?> на fgos.pro так и не прошла. Я сохранила все позиции — их не нужно оформлять заново.</p>

<p><a href="<?php echo htmlspecialchars($recoveryUrlWithUtm); ?>">Вернуться к оплате заказа</a> — <?php echo $formattedAmount; ?> ₽. По ссылке корзина восстановится автоматически.</p>

<p>Если оплата не нужна — ничего делать не требуется, это последнее напоминание по заказу. А если что-то не получалось с оплатой, просто ответьте на это письмо — помогу.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

<?php
/**
 * Touch 1: 1 час после регистрации — личный тон, _personal_layout (anti-«Промоакции»).
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=competition-touch-1h';
$footer_reason = $footer_reason ?? 'оставили заявку на участие в конкурсе на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

$pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Вы оставили заявку на конкурс «<?php echo htmlspecialchars($competition_title); ?>»<?php if (!empty($nomination)): ?> (номинация «<?php echo htmlspecialchars($nomination); ?>»)<?php endif; ?>, но не завершили оформление. Заявка сохранена — её можно дооформить в любой момент.</p>

<p><a href="<?php echo htmlspecialchars($pay_link); ?>">Дооформить заявку на конкурс</a> — <?php echo number_format($competition_price, 0, ',', ' '); ?> ₽.</p>

<p>Диплом приходит в личный кабинет сразу после оплаты. Если возник вопрос — просто ответьте на это письмо.</p>
<?php
$course_block_style = 'personal';
include __DIR__ . '/partials/_course_recommendation.php';
?>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

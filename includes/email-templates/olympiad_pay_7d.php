<?php
/**
 * Олимпиада: 7 дней после прохождения, диплом не оформлен — личный тон.
 */
$footer_reason = 'прошли олимпиаду на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=olympiad-pay-7d';

$pay_link = $payment_url . (strpos($payment_url, '?') !== false ? '&' : '?') . $utm;
$catalog_link = $site_url . '/olimpiady/?' . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Неделю назад вы прошли олимпиаду «<?php echo htmlspecialchars($olympiad_title); ?>» с результатом <?php echo intval($score); ?> из 10 — это <?php echo htmlspecialchars($placement_text); ?>. Диплом за этот результат до сих пор не оформлен.</p>

<p>Хотел уточнить, нужен ли он вам. Если да — ссылка для оформления:</p>

<p><a href="<?php echo htmlspecialchars($pay_link); ?>">Оформить диплом за <?php echo htmlspecialchars($placement_text); ?></a></p>

<p>Если нет — ничего делать не нужно, больше напоминать не будем. По другим олимпиадам можно <a href="<?php echo htmlspecialchars($catalog_link); ?>">посмотреть здесь</a>.</p>

<p>Если что — ответьте на это письмо, я отвечу лично.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

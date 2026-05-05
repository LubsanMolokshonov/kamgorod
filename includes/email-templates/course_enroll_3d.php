<?php
/**
 * Курсы: через 3 дня — финал короткой скидки. Личный тон, без promo/urgency-баннеров.
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=course-enroll-3d';
$ctaUrl = $discount_url ?: $payment_url;
$cta_link = $ctaUrl . (strpos($ctaUrl, '?') !== false ? '&' : '?') . $utm;
$formattedPrice    = number_format($course_price,    0, ',', ' ');
$formattedDiscount = number_format($discount_price,  0, ',', ' ');

$footer_reason = 'оставили заявку на курс на fgos.pro';
$sender_signature = !empty($_sender_name) ? ($_sender_name . ', ФГОС-Практикум') : 'Анна, ФГОС-Практикум';

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Это последнее напоминание по вашей заявке на курс «<?php echo htmlspecialchars($course_title); ?>» (<?php echo (int)$course_hours; ?> ч.). Сегодня заканчивается зафиксированная за вами цена — <?php echo $formattedDiscount; ?> ₽ вместо <?php echo $formattedPrice; ?> ₽.</p>

<p>Если хотите успеть оформить по ней — ссылка ниже:</p>

<p><a href="<?php echo htmlspecialchars($cta_link); ?>">Оформить курс</a></p>

<p>Если решили отложить — без проблем, больше напоминать про эту заявку не буду. Появятся вопросы по программе или формату — просто ответьте на письмо.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

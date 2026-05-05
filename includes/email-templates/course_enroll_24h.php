<?php
/**
 * Курсы: через 24 часа после заявки — личный тон, без promo-баннера.
 * Скидка 10% упоминается в тексте, но без подсветки/срочности и без слова «специально».
 */
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=course-enroll-24h';
$ctaUrl = $discount_url ?: $payment_url;
$cta_link = $ctaUrl . (strpos($ctaUrl, '?') !== false ? '&' : '?') . $utm;
$formattedPrice    = number_format($course_price,    0, ',', ' ');
$formattedDiscount = number_format($discount_price,  0, ',', ' ');

$footer_reason = 'оставили заявку на курс на fgos.pro';
$sender_signature = !empty($_sender_name) ? ($_sender_name . ', ФГОС-Практикум') : 'Анна, ФГОС-Практикум';

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Вчера вы оставили заявку на курс «<?php echo htmlspecialchars($course_title); ?>» (<?php echo (int)$course_hours; ?> ч., <?php echo htmlspecialchars($document_label); ?>) и не завершили оплату.</p>

<p>Хотел уточнить — вам ещё актуально? Если да, я закрепил за вами <?php echo (int)round((1 - $discount_price / $course_price) * 100); ?>% к стоимости — оплата выйдет <?php echo $formattedDiscount; ?> ₽ вместо <?php echo $formattedPrice; ?> ₽. Условие действует 48 часов, потом цена вернётся к обычной.</p>

<p><a href="<?php echo htmlspecialchars($cta_link); ?>">Оформить курс по сниженной цене</a></p>

<p>Если возникли вопросы по программе или документу — просто ответьте на это письмо, я отвечу. Если решили не записываться — ничего делать не нужно.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

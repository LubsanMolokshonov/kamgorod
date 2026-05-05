<?php
/**
 * Олимпиада: 3 дня после заказа диплома. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-3d';
$pay_link = olymp_append_utm($payment_url, $utm);
?>
Здравствуйте, <?= $user_name ?>!

ВЫ ПРОШЛИ ОЛИМПИАДУ <?= olymp_bold_num('3') ?> ДНЯ НАЗАД, но диплом ещё не оформлен.

Вы набрали <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов и заняли <?= $placement_text ?> в олимпиаде «<?= $olympiad_title ?>». Это отличный результат!

Другие участники уже получили свои дипломы и пополнили портфолио. Не откладывайте — оформите диплом прямо сейчас.
<?php if (!empty($has_supervisor) && !empty($supervisor_name)): ?>

Научный руководитель: <?= $supervisor_name ?> (тоже получит диплом)
<?php endif; ?>

Стоимость: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Оформить диплом сейчас:
<?= $pay_link ?>


—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

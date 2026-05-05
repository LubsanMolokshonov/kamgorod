<?php
/**
 * Олимпиада: 1 час после заказа диплома. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-1h';
$pay_link = olymp_append_utm($payment_url, $utm);
?>
Здравствуйте, <?= $user_name ?>!

Вы успешно прошли олимпиаду «<?= $olympiad_title ?>» и показали отличный результат — <?= $placement_text ?>, <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов. Ваш диплом готов к оформлению.
<?php if (!empty($has_supervisor) && !empty($supervisor_name)): ?>

Научный руководитель: <?= $supervisor_name ?> (тоже получит диплом)
<?php endif; ?>

Стоимость диплома: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Получить диплом:
<?= $pay_link ?>


Если у вас возникли вопросы по оплате или оформлению диплома, просто ответьте на это письмо — мы с радостью поможем!

—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

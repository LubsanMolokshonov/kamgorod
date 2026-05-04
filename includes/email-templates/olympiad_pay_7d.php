<?php
/**
 * Олимпиада: 7 дней после заказа диплома — последний шанс. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-7d';
$pay_link = olymp_append_utm($payment_url, $utm);
?>
Здравствуйте, <?= $user_name ?>!

ПРОШЛА НЕДЕЛЯ с момента прохождения олимпиады.

Мы понимаем, что у вас могут быть важные причины отложить оформление. Но мы не хотим, чтобы вы упустили возможность подтвердить свой результат официальным дипломом.

Ваш результат в олимпиаде «<?= $olympiad_title ?>»: <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов, <?= $placement_text ?>.

СПЕЦИАЛЬНОЕ ПРЕДЛОЖЕНИЕ
При оплате <?= olymp_bold_num('2') ?> дипломов — третий БЕСПЛАТНО!
Пройдите ещё олимпиады и сэкономьте до <?= olymp_price_fmt((int)$olympiad_price) ?> ₽.

Стоимость диплома: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Получить диплом:
<?= $pay_link ?>


Выбрать дополнительные олимпиады:
<?= $site_url ?>/olimpiady/?<?= $utm ?>


Это последнее напоминание о вашем дипломе олимпиады.

—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

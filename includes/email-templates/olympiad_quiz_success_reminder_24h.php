<?php
/**
 * Олимпиада: 24ч после успешного теста — не заказал диплом. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-quiz-24h';
$target = !empty($diploma_url) ? $diploma_url : $olympiad_url;
$oly_link = olymp_append_utm($target, $utm);
?>
Здравствуйте, <?= $user_name ?>!

Вчера вы блестяще прошли олимпиаду «<?= $olympiad_title ?>» и заняли <?= $placement_text ?> с результатом <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?>.

Но вы ещё не оформили диплом. НЕ УПУСТИТЕ возможность подтвердить свой результат официальным документом!

Стоимость диплома: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Оформить диплом:
<?= $oly_link ?>


АКЦИЯ <?= olymp_bold_num('2') ?>+<?= olymp_bold_num('1') ?>: при оплате <?= olymp_bold_num('2') ?> дипломов — третий в подарок!
Пройти ещё олимпиады: <?= $site_url ?>/olimpiady/?<?= $utm ?>


—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

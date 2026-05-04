<?php
/**
 * Олимпиада: успешное прохождение теста (≥7 баллов). Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-quiz-success';
$target = !empty($diploma_url) ? $diploma_url : $olympiad_url;
$diploma_link = olymp_append_utm($target, $utm);
?>
Здравствуйте, <?= $user_name ?>!

ПОЗДРАВЛЯЕМ! Вы успешно прошли олимпиаду «<?= $olympiad_title ?>» и заняли <?= $placement_text ?> с результатом <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов.

ВАШ ДИПЛОМ БУДЕТ СОДЕРЖАТЬ:
— ФИО участника и занятое место
— название олимпиады и результат
— официальные реквизиты организатора
— PDF высокого качества для печати

Стоимость диплома: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Оформить диплом:
<?= $diploma_link ?>


Диплом будет доступен в личном кабинете сразу после оплаты.

—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

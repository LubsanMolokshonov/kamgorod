<?php
/**
 * Олимпиада: 1 час после регистрации — не начал тест. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-reg-1h';
$oly_link = olymp_append_utm($olympiad_url, $utm);
?>
Здравствуйте, <?= $user_name ?>!

Вы зарегистрировались на олимпиаду «<?= $olympiad_title ?>», но ещё не начали тест.

Это займёт всего <?= olymp_bold_num('5') ?>–<?= olymp_bold_num('10') ?> минут — <?= olymp_bold_num('10') ?> вопросов с вариантами ответов, без ограничения по времени.

Наберите <?= olymp_bold_num('7') ?>+ баллов и получите призовое место с официальным дипломом!

Пройти тест сейчас:
<?= $oly_link ?>


—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

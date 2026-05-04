<?php
/**
 * Олимпиада: 24 часа после заказа диплома. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-24h';
$pay_link = olymp_append_utm($payment_url, $utm);
?>
Здравствуйте, <?= $user_name ?>!

Вы прошли олимпиаду «<?= $olympiad_title ?>» и заняли <?= $placement_text ?>, но ещё не оформили диплом.

ЧТО ВЫ ПОЛУЧИТЕ ПОСЛЕ ОПЛАТЫ:
— официальный диплом олимпиады в PDF-формате высокого качества
— мгновенное получение в личном кабинете
— возможность скачать и распечатать неограниченное количество раз
— подтверждение результата: <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов, <?= $placement_text ?>
<?php if (!empty($has_supervisor)): ?>— диплом для научного руководителя
<?php endif; ?>— пополнение профессионального портфолио

Стоимость: <?= olymp_price_fmt((int)$olympiad_price) ?> ₽

Оплатить и скачать диплом:
<?= $pay_link ?>


АКЦИЯ <?= olymp_bold_num('2') ?>+<?= olymp_bold_num('1') ?>: при оплате <?= olymp_bold_num('2') ?> дипломов — третий в подарок!
Смотреть каталог олимпиад: <?= $site_url ?>/olimpiady/?<?= $utm ?>


—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

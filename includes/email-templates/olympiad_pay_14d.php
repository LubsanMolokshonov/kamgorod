<?php
/**
 * Олимпиада: 14 дней после заказа диплома — финальное письмо со скидкой 15%/48ч.
 * Скидка выписана в email_campaign_discounts в OlympiadEmailChain::sendChainEmail
 * и применяется автоматически в корзине. Plain-text.
 */
require_once __DIR__ . '/_olympiad_helpers.php';
$utm = 'utm_source=email&utm_campaign=olympiad-pay-14d';
$pay_link = olymp_append_utm($payment_url, $utm);

$discount_rate = isset($discount_rate) ? (float)$discount_rate : 0.15;
$discount_hours = isset($discount_hours) ? (int)$discount_hours : 48;
$price_old = (int)$olympiad_price;
$price_new = (int)round($price_old * (1 - $discount_rate));
$discount_percent = (int)round($discount_rate * 100);
?>
Здравствуйте, <?= $user_name ?>!

Ваш диплом олимпиады «<?= $olympiad_title ?>» всё ещё ждёт оформления. Мы хотим помочь вам довести дело до конца.

ПЕРСОНАЛЬНАЯ СКИДКА <?= olymp_bold_num($discount_percent) ?>% ДЕЙСТВУЕТ <?= olymp_bold_num($discount_hours) ?> ЧАСА

Ваш результат: <?= olymp_bold_num((int)$score) ?> из <?= olymp_bold_num('10') ?> баллов, <?= $placement_text ?>.

Старая цена: <?= olymp_price_fmt($price_old) ?> ₽
Цена со скидкой: <?= olymp_price_fmt($price_new) ?> ₽

Скидка будет ПРИМЕНЕНА АВТОМАТИЧЕСКИ при переходе в корзину — ничего вводить не нужно.

Забрать диплом со скидкой:
<?= $pay_link ?>


Это последнее напоминание о вашем дипломе. Скидка действует <?= olymp_bold_num($discount_hours) ?> часа.

—
Команда ФГОС-Практикум
fgos.pro

Отписаться от рассылки: <?= $unsubscribe_url ?>

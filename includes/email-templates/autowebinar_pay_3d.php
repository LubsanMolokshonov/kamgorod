<?php
/**
 * Email Template: Autowebinar Payment Reminder 3d — финальное (минимальный HTML)
 */

$email_subject = "Не упустите свой сертификат! -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-pay-3d';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p><strong>Это последнее напоминание:</strong> ваш сертификат по вебинару <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong> всё ещё ожидает оплаты.</p>

<p>Вы успешно прошли тест и оформили заявку на сертификат. Осталось только завершить оплату, чтобы получить документ.</p>

<p>
    <strong>Сертификат на:</strong> <?= (int)$certificate_hours ?> академических часа<br>
    <strong>К оплате:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.<br>
    <em>Для аттестации и профессионального портфолио.</em>
</p>

<p><strong>Оплатить и получить сертификат:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

<?php
/**
 * Email Template: Autowebinar Payment Reminder 24h (минимальный HTML)
 */

$email_subject = "Напоминание об оплате сертификата -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-pay-24h';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Напоминаем, что ваш сертификат по вебинару <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong> ожидает оплаты.</p>

<p><strong>Что вы получите:</strong></p>
<p>
— именной сертификат на <strong><?= (int)$certificate_hours ?> часа</strong><br>
— PDF-документ для скачивания и печати<br>
— уникальный регистрационный номер<br>
— QR-код для проверки подлинности
</p>

<p>
    <strong>К оплате:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.<br>
    <strong>Оплатить:</strong> <a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a>
</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

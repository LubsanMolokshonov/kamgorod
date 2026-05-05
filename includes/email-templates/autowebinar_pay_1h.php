<?php
/**
 * Email Template: Autowebinar Payment Reminder 1h (минимальный HTML)
 */

$email_subject = "Завершите оплату сертификата -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-pay-1h';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Вы оформили сертификат по вебинару <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но оплата ещё не завершена.</p>

<p>
    <strong>Сертификат на:</strong> <?= (int)$certificate_hours ?> академических часа<br>
    <strong>К оплате:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.
</p>

<p><strong>Завершить оплату:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<p><em>После оплаты сертификат будет сформирован автоматически</em> — PDF-файл можно будет скачать из личного кабинета.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

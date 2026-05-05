<?php
/**
 * Email Template: Autowebinar Certificate Reminder 24h (минимальный HTML)
 */

$email_subject = "Не забудьте оформить сертификат -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-cert-24h';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Вы прошли тест по видеолекции <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но ещё не оформили сертификат.</p>

<p><strong>Зачем нужен сертификат:</strong></p>
<p>
— подтверждает <strong><?= (int)$certificate_hours ?> часа</strong> повышения квалификации<br>
— пополняет портфолио для аттестации<br>
— содержит уникальный номер и QR-код<br>
— доступен для скачивания в личном кабинете
</p>

<p>
    <strong>Стоимость:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.<br>
    <strong>Оформить:</strong> <a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a>
</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

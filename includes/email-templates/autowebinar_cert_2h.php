<?php
/**
 * Email Template: Autowebinar Certificate Reminder 2h (минимальный HTML)
 */

$email_subject = "Вы прошли тест! Оформите сертификат -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-cert-2h';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p><strong>Поздравляем!</strong> Вы успешно прошли тест по видеолекции <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>. Теперь вы можете оформить именной сертификат участника.</p>

<p>
    <strong>Сертификат на:</strong> <?= (int)$certificate_hours ?> академических часа<br>
    <strong>Стоимость:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.
</p>

<p><strong>Оформить сертификат:</strong><br>
<a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a></p>

<p><em>Что включает сертификат:</em><br>
— ваше ФИО и название вебинара<br>
— уникальный регистрационный номер<br>
— <?= (int)$certificate_hours ?> академических часа для аттестации<br>
— QR-код для проверки подлинности
</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

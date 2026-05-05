<?php
/**
 * Email Template: Autowebinar Certificate Reminder 3d — финальное (минимальный HTML)
 */

$email_subject = "Ваш сертификат ждёт оформления -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-cert-3d';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Вы успешно прошли тест по вебинару <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но сертификат так и не оформлен.</p>

<p><strong>Это последнее напоминание.</strong> Не упустите возможность получить документ для вашего профессионального портфолио.</p>

<p>
    <strong>Сертификат на:</strong> <?= (int)$certificate_hours ?> академических часа<br>
    <strong>Стоимость:</strong> <?= number_format($certificate_price, 0, '', ' ') ?> руб.<br>
    <em>С уникальным номером и QR-кодом для проверки подлинности.</em>
</p>

<p><strong>Оформить сертификат:</strong><br>
<a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a></p>

<p>Личный кабинет: <a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

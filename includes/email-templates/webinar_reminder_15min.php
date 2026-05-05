<?php
/**
 * Email Template: Webinar Reminder 15min (минимальный HTML)
 * За 15 минут до начала — последнее напоминание.
 */

$email_subject = "Через 15 минут начало вебинара!";
$utm = 'utm_source=email&utm_campaign=webinar-reminder-15min';
$cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name) ?></strong>!</p>

<p>До начала вебинара <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong> осталось <strong>15 минут</strong>!</p>

<p><strong>Ссылка на трансляцию:</strong><br>
<a href="<?= htmlspecialchars($broadcast_url) ?>"><?= htmlspecialchars($broadcast_url) ?></a></p>

<p>Войдите прямо сейчас, чтобы занять место.</p>

<p>
    <strong>Начало:</strong> <?= htmlspecialchars($webinar_time) ?> МСК<br>
    <strong>Продолжительность:</strong> <?= (int)$webinar_duration ?> минут
    <?php if ($speaker_name): ?><br><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php endif; ?>
</p>

<p>Не сможете присутствовать? Запись будет в <a href="<?= htmlspecialchars($cab_link) ?>">личном кабинете</a> после окончания.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

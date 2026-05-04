<?php
/**
 * Email Template: Webinar Confirmation (минимальный HTML)
 * Отправляется сразу после регистрации.
 */

$email_subject = "Вы зарегистрированы на вебинар: {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=webinar-confirmation';
$cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
$web_link = $webinar_url . (strpos($webinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name) ?></strong>!</p>

<p>Вы зарегистрированы на бесплатный вебинар <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>. Сохраните дату, чтобы не пропустить.</p>

<p>
    <strong>Когда:</strong> <?= htmlspecialchars($webinar_datetime_full) ?><br>
    <strong>Продолжительность:</strong> <?= (int)$webinar_duration ?> минут
    <?php if ($speaker_name): ?><br><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php if ($speaker_position): ?>, <em><?= htmlspecialchars($speaker_position) ?></em><?php endif; ?><?php endif; ?>
</p>

<p>Сохраните событие в календаре: <a href="<?= htmlspecialchars($google_calendar_url) ?>">Google Calendar</a> · <a href="<?= htmlspecialchars($calendar_url) ?>">файл .ics</a></p>

<p><strong>Важно:</strong> ссылка на трансляцию придёт на эту почту <em>за 1 час до начала</em> вебинара. Также её можно будет найти в <a href="<?= htmlspecialchars($cab_link) ?>">личном кабинете</a>.</p>

<?php if (!empty($webinar_description)): ?>
<p><strong>О чём вебинар?</strong><br><?= nl2br(htmlspecialchars($webinar_description)) ?></p>
<?php endif; ?>

<p>Подробности и программа: <a href="<?= htmlspecialchars($web_link) ?>"><?= htmlspecialchars($web_link) ?></a></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

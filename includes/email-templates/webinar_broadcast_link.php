<?php
/**
 * Email Template: Webinar Broadcast Link (минимальный HTML)
 * За 1 час до начала — главная ссылка на трансляцию.
 */

$email_subject = "Через 1 час начало! Ссылка на вебинар внутри";
$utm = 'utm_source=email&utm_campaign=webinar-broadcast-1h';
$cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name) ?></strong>!</p>

<p>Через <strong>1 час</strong> начнётся вебинар <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>. Начало в <strong><?= htmlspecialchars($webinar_time) ?> МСК</strong>.</p>

<p><strong>Ссылка на трансляцию:</strong><br>
<a href="<?= htmlspecialchars($broadcast_url) ?>"><?= htmlspecialchars($broadcast_url) ?></a></p>

<p><em>Совет:</em> войдите за 5 минут до начала, чтобы проверить звук и изображение.</p>

<p>
    <strong>Когда:</strong> начало в <?= htmlspecialchars($webinar_time) ?> МСК<br>
    <strong>Продолжительность:</strong> <?= (int)$webinar_duration ?> минут
    <?php if ($speaker_name): ?><br><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php endif; ?>
</p>

<p>Не сможете присутствовать? Запись вебинара будет доступна в <a href="<?= htmlspecialchars($cab_link) ?>">личном кабинете</a> после окончания трансляции.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

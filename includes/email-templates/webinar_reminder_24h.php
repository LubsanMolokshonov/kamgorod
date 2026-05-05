<?php
/**
 * Email Template: Webinar Reminder 24h (минимальный HTML)
 * Отправляется за 24 часа до вебинара.
 */

$email_subject = "Завтра вебинар: {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=webinar-reminder-24h';
$web_link = $webinar_url . (strpos($webinar_url, '?') !== false ? '&' : '?') . $utm;
$cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name) ?></strong>!</p>

<p>Напоминаем: <strong>завтра в <?= htmlspecialchars($webinar_time) ?> МСК</strong> состоится вебинар <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, на который вы зарегистрировались.</p>

<p>
    <strong>Когда:</strong> <?= htmlspecialchars($webinar_datetime_full) ?><br>
    <strong>Продолжительность:</strong> <?= (int)$webinar_duration ?> минут
    <?php if ($speaker_name): ?><br><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php if ($speaker_position): ?>, <em><?= htmlspecialchars($speaker_position) ?></em><?php endif; ?><?php endif; ?>
</p>

<?php if (!empty($broadcast_url)): ?>
<p><strong>Ссылка на трансляцию (сохраните):</strong><br>
<a href="<?= htmlspecialchars($broadcast_url) ?>"><?= htmlspecialchars($broadcast_url) ?></a></p>
<?php else: ?>
<p><strong>Ссылка на трансляцию</strong> придёт на эту почту за 1 час до начала. Если письмо не придёт — проверьте папку «Спам».</p>
<?php endif; ?>

<?php if (!empty($webinar_description)): ?>
<p><strong>Что вас ждёт:</strong><br><?= nl2br(htmlspecialchars($webinar_description)) ?></p>
<?php endif; ?>

<p><strong>Как подготовиться:</strong></p>
<p>
— Проверьте стабильность интернет-соединения<br>
— Подготовьте вопросы для спикера<br>
— Выделите время без отвлечений
</p>

<p>Подробнее о вебинаре: <a href="<?= htmlspecialchars($web_link) ?>"><?= htmlspecialchars($web_link) ?></a><br>
Личный кабинет: <a href="<?= htmlspecialchars($cab_link) ?>"><?= htmlspecialchars($cab_link) ?></a></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

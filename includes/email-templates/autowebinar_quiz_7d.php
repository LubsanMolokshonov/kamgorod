<?php
/**
 * Email Template: Autowebinar Quiz Reminder 7d — последний шанс (минимальный HTML)
 */

$email_subject = "Последний шанс получить сертификат -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-quiz-7d';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Неделю назад вы зарегистрировались на видеолекцию <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но тест до сих пор не пройден.</p>

<p><strong>Это последнее напоминание.</strong> Пройдите тест сейчас, чтобы не упустить возможность получить сертификат на <strong><?= (int)$certificate_hours ?> академических часа</strong> для портфолио.</p>

<p>Это займёт всего несколько минут:</p>
<p>
1. Посмотрите запись (можно на ускоренной перемотке)<br>
2. Ответьте на 5 вопросов<br>
3. Получите сертификат
</p>

<p><strong>Пройти тест сейчас:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

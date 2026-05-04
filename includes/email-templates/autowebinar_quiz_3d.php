<?php
/**
 * Email Template: Autowebinar Quiz Reminder 3d (минимальный HTML)
 */

$email_subject = "Напоминание: пройдите тест по вебинару -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-quiz-3d';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Несколько дней назад вы зарегистрировались на видеолекцию <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но тест ещё не пройден.</p>

<p><strong>Преимущества именного сертификата:</strong></p>
<p>
— <strong><?= (int)$certificate_hours ?> академических часа</strong> для аттестации<br>
— уникальный регистрационный номер<br>
— QR-код для проверки подлинности<br>
— подходит для портфолио педагога
</p>

<p><strong>Посмотреть вебинар и пройти тест:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<p><em>Тест состоит из 5 вопросов и его можно проходить повторно.</em></p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

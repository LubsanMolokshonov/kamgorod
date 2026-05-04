<?php
/**
 * Email Template: Autowebinar Quiz Reminder 24h (минимальный HTML)
 */

$email_subject = "Напоминание: пройдите тест по вебинару -- {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-quiz-24h';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Вы зарегистрировались на видеолекцию <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но ещё не прошли тест.</p>

<p>Чтобы получить именной сертификат на <strong><?= (int)$certificate_hours ?> академических часа</strong>, нужно:</p>
<p>
1. Посмотреть запись вебинара<br>
2. Ответить на 5 вопросов теста (нужно 4 из 5 правильных)<br>
3. Оформить сертификат
</p>

<p><strong>Перейти к видеолекции и тесту:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<p><em>Тест можно проходить повторно</em> — если не получится с первого раза, попробуйте ещё.</p>

<?php if ($speaker_name): ?>
<p><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php if ($speaker_position): ?>, <em><?= htmlspecialchars($speaker_position) ?></em><?php endif; ?></p>
<?php endif; ?>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

<?php
/**
 * Email Template: Autowebinar Welcome (минимальный HTML)
 * Сразу после регистрации на видеолекцию.
 */

$email_subject = "Добро пожаловать на видеолекцию: {$webinar_title}";
$utm = 'utm_source=email&utm_campaign=aw-welcome';
$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name ?? $user_name) ?></strong>!</p>

<p>Вы зарегистрированы на бесплатную видеолекцию <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>. Запись доступна <em>прямо сейчас</em> — смотрите в любое удобное время.</p>

<p><strong>Смотреть видеолекцию:</strong><br>
<a href="<?= htmlspecialchars($aw_link) ?>"><?= htmlspecialchars($aw_link) ?></a></p>

<?php if ($speaker_name): ?>
<p><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php if ($speaker_position): ?>, <em><?= htmlspecialchars($speaker_position) ?></em><?php endif; ?></p>
<?php endif; ?>

<p><strong>Как получить именной сертификат:</strong></p>
<p>
1. Посмотрите запись<br>
2. Пройдите короткий тест (5 вопросов)<br>
3. Оформите сертификат на <strong><?= (int)$certificate_hours ?> академических часа</strong> — <?= number_format($certificate_price, 0, '', ' ') ?> руб.
</p>

<p>Документ с уникальным номером и QR-кодом — для портфолио и аттестации.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

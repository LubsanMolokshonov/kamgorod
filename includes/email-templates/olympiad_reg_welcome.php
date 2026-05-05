<?php
/**
 * Олимпиада: сразу после регистрации (до теста) — личный тон.
 */
$footer_reason = 'зарегистрировались на олимпиаду на fgos.pro';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=olympiad-reg-welcome';

$oly_link = $olympiad_url . (strpos($olympiad_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Вы зарегистрировались на олимпиаду «<?php echo htmlspecialchars($olympiad_title); ?>». Спасибо.</p>

<p>Тест короткий — 10 вопросов с вариантами ответов, время не ограничено, результат сразу. Если набираете 7 и больше правильных — дальше можно оформить диплом за призовое место.</p>

<p><a href="<?php echo htmlspecialchars($oly_link); ?>">Перейти к олимпиаде</a></p>

<p>Удачи. Если возникнут вопросы — ответьте на это письмо.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

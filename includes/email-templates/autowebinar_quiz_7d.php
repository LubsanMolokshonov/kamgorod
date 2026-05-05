<?php
/**
 * Видеолекция: тест не пройден через 7 дней — личный тон.
 */
$footer_reason = 'зарегистрировались на видеолекцию на fgos.pro';
$sender_signature = $sender_signature ?? 'Родион, ФГОС-Практикум';
$utm = 'utm_source=email&utm_medium=chain&utm_campaign=aw-quiz-7d';

$aw_link = $autowebinar_url . (strpos($autowebinar_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<p>Здравствуйте, <?php echo htmlspecialchars($user_name); ?>.</p>

<p>Неделю назад вы зарегистрировались на видеолекцию «<?php echo htmlspecialchars($webinar_title); ?>». Тест к ней так и остался не пройден.</p>

<p>Если хотите получить сертификат — посмотрите запись (можно на ускоренной перемотке) и ответьте на 5 вопросов. Это занимает минут 10–15.</p>

<p><a href="<?php echo htmlspecialchars($aw_link); ?>">Перейти к видеолекции и тесту</a></p>

<p>Если уже неактуально — ничего делать не нужно, больше напоминать не будем.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

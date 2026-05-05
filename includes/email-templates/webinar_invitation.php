<?php
/**
 * Приглашение на бесплатный вебинар — личный тон.
 *
 * Subject строится в send_webinar_invitation.php (без слова «бесплатный»).
 */
$utm = 'utm_source=email&utm_medium=invite&utm_campaign=webinar-invite';
$webinar_link = $site_url . '/vebinar/' . $webinar_slug . '?' . $utm;

// Subject — без «бесплатный»/«приглашаем», нейтральный.
$email_subject = 'Вебинар «' . $webinar_title . '» — ' . $webinar_date;

$footer_reason = $footer_reason ?? 'зарегистрированы на fgos.pro и могли бы заинтересоваться этой темой';
$sender_signature = $sender_signature ?? 'Анна, ФГОС-Практикум';

ob_start();
?>
<p>Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>.</p>

<p><?php echo htmlspecialchars($webinar_datetime_full); ?> у нас вебинар «<?php echo htmlspecialchars($webinar_title); ?>». Для участия нужна регистрация — она занимает минуту.</p>

<?php if (!empty($webinar_description)): ?>
<p><?php echo nl2br(htmlspecialchars(mb_substr($webinar_description, 0, 400))); ?></p>
<?php endif; ?>

<?php if (!empty($speaker_name)): ?>
<p>Ведёт <?php echo htmlspecialchars($speaker_name); ?><?php if (!empty($speaker_position)): ?>, <?php echo htmlspecialchars($speaker_position); ?><?php endif; ?>. Длительность около <?php echo (int)$webinar_duration; ?> минут.</p>
<?php endif; ?>

<p><a href="<?php echo htmlspecialchars($webinar_link); ?>">Записаться на вебинар</a></p>

<p>После эфира можно оформить именной сертификат на <?php echo (int)$certificate_hours; ?> ч. — он не обязателен, но иногда нужен для портфолио.</p>

<p>Если тема не ваша — просто проигнорируйте письмо, всё в порядке.</p>
<?php
$content = ob_get_clean();
include __DIR__ . '/_personal_layout.php';

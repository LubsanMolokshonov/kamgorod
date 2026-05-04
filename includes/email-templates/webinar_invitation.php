<?php
/**
 * Email Template: Webinar Invitation (минимальный HTML)
 * Приглашение на вебинар незарегистрированным пользователям.
 */

$utm = 'utm_source=email&utm_medium=invite&utm_campaign=webinar-invitation';
$webinar_link = $site_url . '/vebinar/' . $webinar_slug . '?' . $utm;
$email_subject = "Приглашаем на бесплатный вебинар: {$webinar_title}";
?>
<p>Здравствуйте<?= !empty($user_name) ? ', <strong>' . htmlspecialchars($user_name) . '</strong>' : '' ?>!</p>

<p>Приглашаем вас на <strong>бесплатный вебинар</strong> для педагогов. Регистрация займёт всего 30 секунд.</p>

<p>
    <strong><?= htmlspecialchars($webinar_title) ?></strong><br>
    <strong>Когда:</strong> <?= htmlspecialchars($webinar_datetime_full) ?><br>
    <strong>Продолжительность:</strong> <?= (int)$webinar_duration ?> минут
    <?php if (!empty($speaker_name)): ?><br><strong>Спикер:</strong> <?= htmlspecialchars($speaker_name) ?><?php if (!empty($speaker_position)): ?>, <em><?= htmlspecialchars($speaker_position) ?></em><?php endif; ?><?php endif; ?>
</p>

<p><strong>Зарегистрироваться бесплатно:</strong><br>
<a href="<?= htmlspecialchars($webinar_link) ?>"><?= htmlspecialchars($webinar_link) ?></a></p>

<?php if (!empty($webinar_description)): ?>
<p><strong>О чём вебинар:</strong><br><?= nl2br(htmlspecialchars($webinar_description)) ?></p>
<?php endif; ?>

<p>После вебинара вы сможете получить именной сертификат на <strong><?= (int)$certificate_hours ?> часа</strong> — <?= number_format($certificate_price, 0, ',', ' ') ?> ₽.</p>

<p><em>Количество мест ограничено</em> — зарегистрируйтесь сейчас, чтобы гарантировать себе место и получить ссылку на трансляцию.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<?php if (!empty($unsubscribe_url)): ?>
<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>
<?php endif; ?>

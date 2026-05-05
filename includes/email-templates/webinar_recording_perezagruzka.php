<?php
/**
 * Email Template: Запись вебинара «Перезагрузка отношений с родителями» (минимальный HTML)
 */

$email_subject = "Запись вебинара «Перезагрузка отношений с родителями» — смотрите бесплатно";
$utm = 'utm_source=email&utm_campaign=recording_perezagruzka';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
$cabinet_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
$rec_link = $recording_url . (strpos($recording_url, '?') !== false ? '&' : '?') . $utm;
$pres_link = $presentation_url . (strpos($presentation_url, '?') !== false ? '&' : '?') . $utm;
$fb_link = $feedback_url . (strpos($feedback_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_name) ?></strong>!</p>

<p>23 апреля состоялся вебинар <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>. Запись уже доступна — смотрите в любое удобное время.</p>

<p><strong>Смотреть запись:</strong><br>
<a href="<?= htmlspecialchars($rec_link) ?>"><?= htmlspecialchars($rec_link) ?></a></p>

<p><strong>Полезные материалы:</strong><br>
— <a href="<?= htmlspecialchars($pres_link) ?>">скачать презентацию с полезными материалами</a><br>
— <a href="<?= htmlspecialchars($fb_link) ?>">заполнить анкету обратной связи</a>
</p>

<p><strong>Получите именной сертификат участника</strong> на <strong><?= (int)$certificate_hours ?> академических часа</strong> — <?= number_format($certificate_price, 0, '', ' ') ?> руб.<br>
Оформить: <a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a></p>

<p>Следите за новыми вебинарами в <a href="<?= htmlspecialchars($cabinet_link) ?>">личном кабинете</a>.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<?php if (!empty($unsubscribe_url)): ?>
<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>
<?php endif; ?>

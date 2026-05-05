<?php
/**
 * Email Template: Apology — Certificate Technical Issue Fixed (минимальный HTML)
 * Разовое письмо-извинение для пользователей, столкнувшихся с ошибкой оформления.
 */

$email_subject = "Приносим извинения — оформление сертификата восстановлено";
$utm = 'utm_source=email&utm_campaign=apology_certificate';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_name) ?></strong>!</p>

<p>После вебинара <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong> вы переходили по ссылке для оформления сертификата, но столкнулись с технической ошибкой.</p>

<p><strong>Что произошло:</strong> из-за технического сбоя на нашей стороне страница оформления сертификата была временно недоступна. Приносим искренние извинения за неудобства.</p>

<p><strong>Проблема полностью устранена</strong> — сейчас всё работает корректно, вы можете оформить сертификат прямо сейчас.</p>

<p><strong>Именной сертификат участника</strong> на <strong><?= (int)$certificate_hours ?> академических часа</strong>.<br>
Стоимость: <strong><?= number_format($certificate_price, 0, '', ' ') ?> руб.</strong><br>
Оформить: <a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a></p>

<p>Если у вас возникнут вопросы — напишите на <a href="mailto:info@fgos.pro">info@fgos.pro</a>, мы обязательно поможем.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

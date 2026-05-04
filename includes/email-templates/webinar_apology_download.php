<?php
/**
 * Email Template: Apology — Certificate Download Fixed + PDF Attached (минимальный HTML)
 * PDF сертификата прикрепляется к письму скриптом.
 */

$email_subject = "Ваш сертификат готов — приносим извинения за задержку";
$utm = 'utm_source=email&utm_campaign=apology_download_certificate';
$cabinet_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_name) ?></strong>!</p>

<p>Вы оплатили сертификат участника вебинара <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>, но из-за технического сбоя на нашей стороне скачивание было временно недоступно.</p>

<p><strong>Приносим искренние извинения!</strong> Понимаем, как это неприятно. Проблема полностью устранена.</p>

<p><strong>Ваш сертификат прикреплён к этому письму</strong> — вы можете сохранить его прямо сейчас.</p>

<p>
    <strong>Получатель:</strong> <?= htmlspecialchars($user_name) ?><br>
    <strong>На:</strong> <?= (int)$certificate_hours ?> академических часа<br>
    <strong>Номер документа:</strong> <?= htmlspecialchars($certificate_number ?? '—') ?>
</p>

<p>Также сертификат всегда можно скачать в <a href="<?= htmlspecialchars($cabinet_link) ?>">личном кабинете</a>.</p>

<p>Если возникнут вопросы — напишите на <a href="mailto:info@fgos.pro">info@fgos.pro</a>, мы обязательно поможем.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

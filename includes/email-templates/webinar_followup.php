<?php
/**
 * Email Template: Webinar Follow-up (минимальный HTML)
 * Через 3 часа после начала — благодарность, запись, сертификат.
 */

$email_subject = "Спасибо за участие в вебинаре! Запись и сертификат";
$utm = 'utm_source=email&utm_campaign=pismoposle1veba';
$cert_link = $certificate_url . (strpos($certificate_url, '?') !== false ? '&' : '?') . $utm;
$cab_link = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
?>
<p>Здравствуйте, <strong><?= htmlspecialchars($user_first_name) ?></strong>!</p>

<p>Благодарим вас за участие в вебинаре <strong>«<?= htmlspecialchars($webinar_title) ?>»</strong>! Надеемся, материал был полезным для вашей профессиональной деятельности.</p>

<p><strong>Запись вебинара</strong> будет отправлена вам на почту в течение суток.</p>

<p><strong>Бонус для участников:</strong><br>
— Презентация и подарок от спикера: <a href="https://clck.ru/3SaKHd">скачать материалы</a><br>
— Анкета обратной связи (2 минуты): <a href="https://clck.ru/3SaLJ4">заполнить анкету</a>
</p>

<p><strong>Получите именной сертификат участника</strong> на <strong><?= (int)$certificate_hours ?> академических часа</strong> для аттестации и портфолио.<br>
Стоимость: <strong><?= number_format($certificate_price, 0, '', ' ') ?> руб.</strong><br>
Оформить: <a href="<?= htmlspecialchars($cert_link) ?>"><?= htmlspecialchars($cert_link) ?></a></p>

<p><em>Что включает сертификат:</em><br>
— ваше ФИО и название вебинара<br>
— уникальный регистрационный номер<br>
— <?= (int)$certificate_hours ?> академических часа для аттестации<br>
— QR-код для проверки подлинности
</p>

<p>Следите за новыми вебинарами в <a href="<?= htmlspecialchars($cab_link) ?>">личном кабинете</a>.</p>

<hr>
<p><em>С уважением, команда <strong>ФГОС-Практикум</strong><br>
<a href="<?= htmlspecialchars($site_url) ?>"><?= htmlspecialchars($site_url) ?></a></em></p>

<p style="font-size:12px;color:#888;">
    Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url) ?>">отписаться от рассылки</a>.
</p>

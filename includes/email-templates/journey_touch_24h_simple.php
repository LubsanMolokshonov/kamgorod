<?php
/**
 * Touch 24h — minimal HTML (warmup-режим до 2026-05-11)
 * Бренд: ФГОС-Практикум
 */
?>
<p>Здравствуйте, <?= htmlspecialchars($user_name, ENT_QUOTES, 'UTF-8') ?>!</p>

<p>Прошли сутки с вашей регистрации на конкурс <strong>«<?= htmlspecialchars($competition_title, ENT_QUOTES, 'UTF-8') ?>»</strong>, а заявка <em>всё ещё не оплачена</em>.</p>

<p>После оплаты вы получите:</p>
<p>
&mdash; <strong>официальный диплом</strong> в PDF (всероссийский конкурс);<br>
&mdash; <em>мгновенную выдачу</em> в личный кабинет — печатать можно сколько угодно раз;<br>
&mdash; пополнение профессионального портфолио для аттестации.
</p>

<?php if (!empty($nomination) || !empty($work_title)): ?>
<p>
    <?php if (!empty($nomination)): ?>Номинация: <?= htmlspecialchars($nomination, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
    <?php if (!empty($nomination) && !empty($work_title)): ?><br><?php endif; ?>
    <?php if (!empty($work_title)): ?>Работа: «<?= htmlspecialchars($work_title, ENT_QUOTES, 'UTF-8') ?>»<?php endif; ?>
</p>
<?php endif; ?>

<p>Стоимость участия — <strong><?= number_format($competition_price, 0, ',', ' ') ?> руб.</strong></p>

<p>Завершить оплату: <a href="<?= htmlspecialchars($payment_url, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($payment_url, ENT_QUOTES, 'UTF-8') ?></a></p>

<p>—<br>С уважением,<br>Команда ФГОС-Практикум<br>fgos.pro</p>

<p style="font-size:12px;color:#888">Если письмо пришло по ошибке — <a href="<?= htmlspecialchars($unsubscribe_url, ENT_QUOTES, 'UTF-8') ?>">отписаться от рассылки</a>.</p>

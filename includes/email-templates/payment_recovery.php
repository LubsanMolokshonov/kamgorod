<?php
/**
 * Письмо: Recovery после неудачной оплаты.
 * Отправляется PaymentRecoveryChain через 30 мин–24ч после failed-заказа,
 * если у пользователя нет succeeded-заказа в окне.
 *
 * Переменные: $user_name, $order_number, $final_amount, $items[],
 *             $recovery_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_medium=transactional&utm_campaign=payment_recovery';
$formattedAmount = number_format($final_amount, 0, ',', ' ');
$recoveryUrlWithUtm = $recovery_url . (strpos($recovery_url, '?') !== false ? '&' : '?') . $utm;

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo SITE_URL; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px;">
        </div>
        <h1>Ваш заказ ждёт оплаты</h1>
        <p>Мы сохранили все позиции — продолжите в один клик</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Вы оформили заказ на портале fgos.pro, но оплата так и не прошла. Это бывает: ссылка на оплату от банка действует ограниченное время, могла истечь сессия или платёж был отменён.</p>

    <p><strong>Хорошая новость:</strong> мы сохранили все ваши позиции. Нажмите кнопку ниже — мы автоматически восстановим корзину и откроем страницу оплаты.</p>

    <div class="competition-card">
        <span class="badge">Заказ <?php echo htmlspecialchars($order_number); ?></span>
        <h3>Что в вашем заказе:</h3>
        <ul style="list-style: none; padding: 0; margin: 16px 0;">
            <?php foreach ($items as $item): ?>
                <li style="padding: 12px 0; border-bottom: 1px solid #eceef6;">
                    <?php if (!empty($item['olympiad_registration_id'])): ?>
                        <strong style="color: #182f8a;"><?php echo htmlspecialchars($item['olympiad_title'] ?? 'Олимпиада'); ?></strong><br>
                        <span style="color: #5a608a; font-size: 14px;">
                            Диплом олимпиады
                            <?php if (!empty($item['olympiad_placement'])): ?>
                                • <?php echo $item['olympiad_placement'] == '1' ? '1 место' : ($item['olympiad_placement'] == '2' ? '2 место' : '3 место'); ?>
                            <?php endif; ?>
                        </span>
                    <?php elseif (!empty($item['webinar_certificate_id'])): ?>
                        <strong style="color: #182f8a;"><?php echo htmlspecialchars($item['webinar_title'] ?? 'Вебинар'); ?></strong><br>
                        <span style="color: #5a608a; font-size: 14px;">Сертификат участника вебинара</span>
                    <?php elseif (!empty($item['certificate_id'])): ?>
                        <strong style="color: #182f8a;"><?php echo htmlspecialchars($item['publication_title'] ?? 'Публикация'); ?></strong><br>
                        <span style="color: #5a608a; font-size: 14px;">Свидетельство о публикации</span>
                    <?php elseif (!empty($item['registration_id'])): ?>
                        <strong style="color: #182f8a;"><?php echo htmlspecialchars($item['competition_title'] ?? 'Конкурс'); ?></strong><br>
                        <span style="color: #5a608a; font-size: 14px;">
                            <?php if (!empty($item['nomination'])): ?>
                                Номинация: <?php echo htmlspecialchars($item['nomination']); ?>
                            <?php else: ?>
                                Участие в конкурсе
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
        <p class="price-tag">
            <?php echo $formattedAmount; ?> <small>₽ к оплате</small>
        </p>
    </div>

    <div class="text-center" style="margin: 32px 0;">
        <a href="<?php echo htmlspecialchars($recoveryUrlWithUtm); ?>" class="cta-button">
            Оплатить в один клик
        </a>
    </div>

    <p class="text-muted text-small" style="margin-top: 24px;">
        Ссылка действует 72 часа. После клика вы автоматически попадёте в свою корзину — повторно вводить данные не потребуется.
    </p>

    <p class="text-muted text-small">
        Если у вас возникли сложности с оплатой или возник вопрос — просто ответьте на это письмо, мы поможем.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

<?php
/**
 * Письмо: Активация пожизненной скидки.
 * Отправляется один раз — сразу после первого успешного платежа.
 *
 * Переменные:
 *   $user_name, $order_number,
 *   $cart_discount_percent, $course_discount_percent,
 *   $recommended_title, $recommended_url, $recommended_type_label, $recommended_price,
 *   $has_recommendation (bool),
 *   $cabinet_url, $catalog_url, $unsubscribe_url, $site_url
 */
$utm = 'utm_source=email&utm_campaign=lifetime-discount-granted';
$catalogLink = $catalog_url . (strpos($catalog_url, '?') !== false ? '&' : '?') . $utm;
$cabinetLink = $cabinet_url . (strpos($cabinet_url, '?') !== false ? '&' : '?') . $utm;
$recommendationLink = '';
if (!empty($has_recommendation) && !empty($recommended_url)) {
    $recommendationLink = $recommended_url . (strpos($recommended_url, '?') !== false ? '&' : '?') . $utm;
}
$formattedPrice = !empty($recommended_price) ? number_format((float)$recommended_price, 0, ',', ' ') . ' ₽' : '';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <h1>Спасибо за покупку!</h1>
        <p>Теперь за вами закреплена пожизненная скидка</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name); ?>!</p>

    <p>Ваш заказ <strong>№<?php echo htmlspecialchars($order_number); ?></strong> успешно оплачен — благодарим за доверие к педагогическому порталу «Каменный город».</p>

    <div style="background: linear-gradient(135deg, #f5f0ff 0%, #ede7ff 100%); border: 2px solid #a78bfa; border-radius: 14px; padding: 28px; margin: 28px 0; text-align: center;">
        <div style="font-size: 42px; line-height: 1; margin-bottom: 10px;">🏆</div>
        <h2 style="margin: 0 0 10px; color: #6d28d9; font-size: 22px;">Пожизненная скидка активирована</h2>
        <p style="margin: 0 0 14px; color: #4c1d95; font-size: 16px;">Теперь на всех покупках на портале вас ждут специальные цены:</p>
        <table cellpadding="0" cellspacing="0" border="0" style="margin: 0 auto;">
            <tr>
                <td style="padding: 6px 18px; background: #fff; border-radius: 8px; margin: 0 6px;">
                    <strong style="color: #6d28d9; font-size: 24px;">−<?php echo (int)$cart_discount_percent; ?>%</strong><br>
                    <span style="color: #64748b; font-size: 13px;">конкурсы, олимпиады,<br>вебинары, публикации</span>
                </td>
                <td style="width: 12px;"></td>
                <td style="padding: 6px 18px; background: #fff; border-radius: 8px;">
                    <strong style="color: #6d28d9; font-size: 24px;">−<?php echo (int)$course_discount_percent; ?>%</strong><br>
                    <span style="color: #64748b; font-size: 13px;">курсы повышения квалификации<br>и переподготовки</span>
                </td>
            </tr>
        </table>
        <p style="margin: 16px 0 0; color: #4c1d95; font-size: 14px;">Скидки применяются автоматически при оформлении любого следующего заказа — вам ничего не нужно вводить.</p>
    </div>

    <?php if (!empty($has_recommendation)): ?>
        <h3 style="color: #1e293b; margin: 30px 0 12px;">Рекомендуем забрать со скидкой</h3>
        <p style="color: #475569; margin: 0 0 16px;">Подобрали для вас то, что подходит под вашу аудиторию:</p>

        <div style="background: #ffffff; border: 1px solid #e2e8f0; border-radius: 12px; padding: 22px; margin-bottom: 24px;">
            <?php if (!empty($recommended_type_label)): ?>
                <span style="display: inline-block; background: #ede9fe; color: #6d28d9; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 600; margin-bottom: 10px;">
                    <?php echo htmlspecialchars($recommended_type_label); ?>
                </span>
            <?php endif; ?>
            <h4 style="margin: 0 0 10px; color: #1e293b; font-size: 18px;">
                <?php echo htmlspecialchars($recommended_title); ?>
            </h4>
            <?php if ($formattedPrice): ?>
                <p style="margin: 0 0 16px; color: #475569; font-size: 14px;">
                    Стоимость: <strong><?php echo $formattedPrice; ?></strong>
                    <span style="color: #6d28d9;">(с учётом вашей скидки — ещё выгоднее)</span>
                </p>
            <?php endif; ?>
            <div class="text-center" style="margin-top: 8px;">
                <a href="<?php echo htmlspecialchars($recommendationLink); ?>" class="cta-button" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);">
                    Посмотреть и оформить
                </a>
            </div>
        </div>
    <?php else: ?>
        <h3 style="color: #1e293b; margin: 30px 0 12px;">Начните экономить прямо сейчас</h3>
        <p style="color: #475569; margin: 0 0 20px;">Загляните в каталог — скидка применится автоматически на любой ваш следующий заказ.</p>
        <div class="text-center" style="margin: 20px 0 28px;">
            <a href="<?php echo htmlspecialchars($catalogLink); ?>" class="cta-button" style="background: linear-gradient(135deg, #7c3aed 0%, #6d28d9 100%);">
                Перейти в каталог
            </a>
        </div>
    <?php endif; ?>

    <p style="color: #64748b; font-size: 14px; margin-top: 24px;">
        Статус постоянного клиента виден в вашем <a href="<?php echo htmlspecialchars($cabinetLink); ?>" style="color: #6d28d9;">личном кабинете</a> — там же собраны все ваши документы и заявки.
    </p>

    <p class="text-muted text-small" style="margin-top: 30px;">
        Если у вас возникнут вопросы — просто ответьте на это письмо, мы будем рады помочь.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

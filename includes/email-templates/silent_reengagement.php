<?php
/**
 * Шаблон реактивации «молчащих» пользователей с предложением скидки 10%.
 *
 * Переменные:
 *   $user_name, $site_url, $site_name, $unsubscribe_url
 *   $discount_percent (10), $discount_expires_label (например «30 апреля»)
 *   $magic_login_url       — автовход в ЛК (ссылка в тело письма / CTA)
 *   $primary_cta_url       — основная CTA (зависит от сегмента)
 *   $primary_cta_label     — подпись основной CTA
 *   $segment_code          — A/B/C/D/E/F/G (для UTM)
 *   $headline              — персональный заголовок
 *   $intro_text            — 1 короткий абзац
 *   $recommendations       — array: [ ['title'=>..., 'description'=>..., 'url'=>..., 'badge'=>..., 'price'=>?, 'meta'=>?], ... ]
 *   $footer_reason
 */

$email_subject = 'Скидка ' . (int)$discount_percent . '% до ' . htmlspecialchars($discount_expires_label) . ' — специально для вас';

$utm = '?utm_source=email&utm_medium=campaign&utm_campaign=silent_reengagement_10&utm_content=' . urlencode($segment_code ?? 'na');

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo htmlspecialchars($site_url); ?>/assets/images/logo-white.png" alt="<?php echo htmlspecialchars($site_name); ?>" style="height: 40px;">
        </div>
        <h1><?php echo htmlspecialchars($headline); ?></h1>
        <p>Скидка <?php echo (int)$discount_percent; ?>% на любую покупку до <?php echo htmlspecialchars($discount_expires_label); ?></p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте, <?php echo htmlspecialchars($user_name ?: 'коллега'); ?>!</p>

    <p><?php echo htmlspecialchars($intro_text); ?></p>

    <!-- Плашка скидки -->
    <div class="urgency-banner" style="margin: 25px 0; background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%); color: #78350f; border-radius: 14px; padding: 20px; text-align: center;">
        <div style="font-size: 28px; margin-bottom: 6px;">🎁</div>
        <strong style="font-size: 18px;">Скидка <?php echo (int)$discount_percent; ?>% до <?php echo htmlspecialchars($discount_expires_label); ?></strong>
        <p style="margin: 8px 0 0 0; font-size: 14px;">
            Применится автоматически в корзине и при оплате курса — ничего вводить не нужно. Нужно только войти в личный кабинет по кнопке ниже.
        </p>
    </div>

    <?php if (!empty($recommendations)): ?>
        <h3 style="margin-top: 30px; color: #1e40af;">Что посмотреть</h3>
        <?php foreach ($recommendations as $rec): ?>
            <div class="competition-card">
                <?php if (!empty($rec['badge'])): ?>
                    <span class="badge"><?php echo htmlspecialchars($rec['badge']); ?></span>
                <?php endif; ?>
                <h3><?php echo htmlspecialchars($rec['title']); ?></h3>
                <?php if (!empty($rec['description'])): ?>
                    <p style="color: #475569; font-size: 14px; margin: 10px 0;">
                        <?php echo htmlspecialchars(mb_substr($rec['description'], 0, 180)); ?>
                    </p>
                <?php endif; ?>
                <?php if (!empty($rec['meta'])): ?>
                    <div class="competition-details">
                        <?php foreach ($rec['meta'] as $mlabel => $mval): ?>
                            <p><strong><?php echo htmlspecialchars($mlabel); ?>:</strong> <?php echo htmlspecialchars($mval); ?></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if (!empty($rec['price'])): ?>
                    <div class="price-tag"><?php echo number_format((float)$rec['price'], 0, ',', ' '); ?> &#8381;</div>
                <?php endif; ?>
                <div style="margin-top: 14px;">
                    <a href="<?php echo htmlspecialchars($rec['url'] . $utm); ?>" class="cta-button cta-button-green">
                        Подробнее
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- Главная CTA: войти в ЛК, там скидка активна -->
    <div class="text-center" style="margin-top: 30px;">
        <a href="<?php echo htmlspecialchars($magic_login_url); ?>" class="cta-button">
            <?php echo htmlspecialchars($primary_cta_label ?: 'Войти в личный кабинет со скидкой'); ?>
        </a>
    </div>

    <p style="margin-top: 25px; font-size: 13px; color: #64748b; line-height: 1.5; text-align: center;">
        Скидка автоматически появится в корзине и при оплате курса после входа в ЛК. Действует до 23:59 <?php echo htmlspecialchars($discount_expires_label); ?> по одной покупке.
    </p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

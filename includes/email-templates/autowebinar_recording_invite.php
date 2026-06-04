<?php
/**
 * Письмо разовой рассылки по записи автовебинара «Полезное лето. Особый ребёнок».
 * Материалы вебинара + magic-link на оформление именного диплома.
 * Использует _webinar_base_layout.php.
 *
 * Ожидаемые переменные:
 *   $user_name, $webinar_title,
 *   $speaker_name, $speaker_position, $speaker_photo,
 *   $certificate_hours, $certificate_price,
 *   $site_url, $unsubscribe_url,
 *   $claim_link        — magic-link на pages/autowebinar-claim.php (диплом)
 *   $presentation_url  — презентация эксперта
 *   $feedback_url      — анкета обратной связи (за заполнение — подарок)
 *   $recording_url     — запись вебинара
 */

$email_subject = 'Запись вебинара и материалы — «' . ($webinar_title ?? 'Полезное лето. Особый ребёнок') . '»';

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Материалы вебинара готовы</h1>
        <p>Запись, презентация и именной диплом участника</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>!</p>

    <p>Мы подготовили материалы вебинара «<?php echo htmlspecialchars($webinar_title); ?>»<?php echo !empty($speaker_name) ? ' (' . htmlspecialchars($speaker_name) . ')' : ''; ?>. Теперь его можно посмотреть в записи в удобное время и оформить именной диплом участника.</p>

    <div class="webinar-card">
        <h3>Материалы вебинара</h3>
        <div class="webinar-details">
            <p>
                <span class="icon">—</span>
                <a href="<?php echo htmlspecialchars($recording_url); ?>" target="_blank">Запись вебинара</a>
            </p>
            <p>
                <span class="icon">—</span>
                <a href="<?php echo htmlspecialchars($presentation_url); ?>" target="_blank">Презентация эксперта</a>
            </p>
            <p>
                <span class="icon">—</span>
                <a href="<?php echo htmlspecialchars($feedback_url); ?>" target="_blank">Анкета обратной связи</a> — за заполнение подарок
            </p>
        </div>
    </div>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($claim_link); ?>" class="cta-button cta-button-green" target="_blank">
            Оформить диплом участника
        </a>
    </div>

    <div class="info-block">
        <p><strong>Диплом оформляется в пару кликов.</strong> По кнопке выше вы попадёте сразу на страницу оформления — вход и регистрация на вебинар произойдут автоматически.</p>
    </div>

    <?php if (!empty($certificate_hours)): ?>
    <div class="certificate-card">
        <h3>Именной диплом участника</h3>
        <p style="color:#7a4f00; margin: 6px 0 12px;">Подходит для портфолио и аттестации.</p>
        <div class="price"><?php echo (int)($certificate_price ?? 200); ?> ₽ <small>· <?php echo (int)$certificate_hours; ?> ак. часа</small></div>
    </div>
    <?php endif; ?>

    <?php if (!empty($speaker_name) && !empty($speaker_photo)): ?>
    <div class="speaker-card">
        <img src="<?php echo htmlspecialchars($speaker_photo); ?>" alt="<?php echo htmlspecialchars($speaker_name); ?>" class="speaker-photo">
        <div class="speaker-info">
            <h4><?php echo htmlspecialchars($speaker_name); ?></h4>
            <?php if (!empty($speaker_position)): ?>
            <p><?php echo htmlspecialchars($speaker_position); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <p class="text-muted" style="font-size: 14px; margin-top: 28px;">Если диплом вам сейчас не нужен — просто посмотрите запись и материалы, они останутся доступны.</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

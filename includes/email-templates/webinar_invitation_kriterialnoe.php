<?php
/**
 * Брендированное приглашение на бесплатный вебинар «Критериальное оценивание»
 * (массовая рассылка по базе учителей школ). Использует _webinar_base_layout.php.
 *
 * Ожидаемые переменные:
 *   $user_name, $webinar_title, $webinar_slug, $webinar_description,
 *   $webinar_datetime_full, $webinar_duration,
 *   $speaker_name, $speaker_position, $speaker_photo,
 *   $certificate_hours, $certificate_price,
 *   $site_url, $unsubscribe_url, $webinar_link, $webinar_date
 */

// $webinar_link приходит из вызывающего скрипта — magic-link или обычный URL для теста.
$webinar_link = $webinar_link ?? ($site_url . '/vebinar/' . $webinar_slug . '/');

$email_subject = 'Критериальное оценивание — вебинар ' . ($webinar_date ?? '21 мая');

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-white.png" alt="ФГОС-Практикум" style="height: 40px; vertical-align: middle;">
            <img src="<?php echo $site_url; ?>/assets/images/logo-kamenny-gorod-white.png" alt="Каменный Город" style="height: 40px; vertical-align: middle; margin-left: 20px;">
        </div>
        <h1>Приглашаем на бесплатный вебинар</h1>
        <p>Для учителей школ</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>!</p>

    <p>«А за что тройка?» — вопрос, который слышал каждый учитель. Когда критерии оценки в голове, а не на бумаге, спорить можно бесконечно. Критериальное оценивание убирает этот спор: и ученик, и родитель видят, из чего сложилась отметка. Мы собрали практический разбор — без теории ради теории.</p>

    <div class="webinar-card">
        <span class="badge">Бесплатный вебинар</span>
        <h3><?php echo htmlspecialchars($webinar_title); ?></h3>
        <div class="webinar-details">
            <p>
                <span class="icon">📅</span>
                <strong><?php echo htmlspecialchars($webinar_datetime_full); ?></strong>
            </p>
            <p>
                <span class="icon">⏱️</span>
                Продолжительность: <?php echo (int)$webinar_duration; ?> минут
            </p>
            <?php if (!empty($speaker_name)): ?>
            <p>
                <span class="icon">👤</span>
                Спикер: <?php echo htmlspecialchars($speaker_name); ?><?php if (!empty($speaker_position)): ?>, <?php echo htmlspecialchars($speaker_position); ?><?php endif; ?>
            </p>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!empty($webinar_description)): ?>
    <h3 style="color: #182f8a; margin-top: 28px; font-family: 'Onest','Inter',sans-serif; font-size: 18px;">О чём поговорим</h3>
    <p class="text-muted"><?php echo nl2br(htmlspecialchars(mb_substr($webinar_description, 0, 600))); ?></p>
    <?php endif; ?>

    <ul style="color:#2a3056; padding-left: 20px; margin: 18px 0;">
        <li style="margin-bottom: 8px;">7 готовых инструментов: рубрикаторы, чек-листы, речевые скрипты обратной связи.</li>
        <li style="margin-bottom: 8px;">Приёмы самооценки и взаимооценки, которые работают в обычном расписании.</li>
        <li style="margin-bottom: 8px;">Как сделать критерии понятными ученику — и снять конфликты из-за отметок.</li>
        <li style="margin-bottom: 8px;">Что показать родителям, чтобы вопрос «за что оценка» больше не возникал.</li>
    </ul>

    <p class="text-muted">85% времени — практика: разбираем готовые шаблоны по ФГОС, которые можно взять на ближайший урок.</p>

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

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($webinar_link); ?>" class="cta-button cta-button-green" target="_blank">
            Записаться на вебинар
        </a>
    </div>

    <div class="info-block">
        <p><strong>Не сможете быть в эфире?</strong> Всё равно регистрируйтесь — пришлём ссылку на запись после трансляции.</p>
    </div>

    <?php if (!empty($certificate_hours)): ?>
    <div class="certificate-card">
        <h3>Именной сертификат участника</h3>
        <p style="color:#7a4f00; margin: 6px 0 12px;">Подходит для портфолио и аттестации. Оформляется по желанию.</p>
        <div class="price"><?php echo (int)($certificate_price ?? 200); ?> ₽ <small>· <?php echo (int)$certificate_hours; ?> ак. часа</small></div>
    </div>
    <?php endif; ?>

    <p class="text-muted" style="font-size: 14px; margin-top: 28px;">Если тема не ваша — просто проигнорируйте письмо, всё в порядке. А если знаете коллегу, кому это нужнее — перешлите.</p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_webinar_base_layout.php';

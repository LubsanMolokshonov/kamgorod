<?php
/**
 * Напоминание о постоянной скидке лояльности + персональная подборка вебинаров.
 * Кампания lifetime25_webinars_jul2026 (scripts/send_lifetime_webinar_reminder.php).
 *
 * Переменные:
 *   $user_name                 — имя получателя ('' если нет)
 *   $discount_percent          — ставка скидки на корзину, целое (обычно 25)
 *   $course_discount_percent   — ставка скидки на курсы, целое (обычно 10)
 *   $webinars                  — массив до 3 шт.: [title, url, badge, price, price_discounted, hours]
 *   $catalog_url               — ссылка на каталог видеолекций (magic-link)
 *   $sender_signature, $unsubscribe_url, $site_url, $footer_reason, $email_subject
 */

$fmtPrice = function ($v) {
    $v = (float)$v;
    return abs($v - round($v)) < 0.005
        ? number_format($v, 0, ',', ' ')
        : number_format($v, 2, ',', ' ');
};

ob_start();
?>
<div class="email-header">
    <div class="email-header-content">
        <div class="logo" style="text-align: center;">
            <span class="logo-text" style="color: #ffffff; font-size: 24px; font-weight: 700; letter-spacing: -0.02em;">ФГОС-Практикум</span>
        </div>
        <h1>У вас есть постоянная скидка &minus;<?php echo (int)$discount_percent; ?>%</h1>
        <p>Она закреплена за вашим аккаунтом и не сгорает — напоминаем, куда её применить</p>
    </div>
</div>

<div class="email-content">
    <p class="greeting">Здравствуйте<?php echo $user_name ? ', ' . htmlspecialchars($user_name) : ''; ?>!</p>

    <p>Вы уже оплачивали участие на fgos.pro — с этого момента за вашим аккаунтом закреплена
    <strong>постоянная скидка</strong>. Она без срока действия и без промокодов: цена пересчитывается
    автоматически при оплате из вашего аккаунта.</p>

    <ul class="benefits-list">
        <li><strong>&minus;<?php echo (int)$discount_percent; ?>%</strong> — вебинары, конкурсы, олимпиады и публикации</li>
        <li><strong>&minus;<?php echo (int)$course_discount_percent; ?>%</strong> — курсы повышения квалификации и переподготовки</li>
    </ul>

    <p>Многие про эту скидку забывают, поэтому напоминаем — и заодно подобрали вебинары под ваш профиль.
    Все они в записи: смотрите в удобное время, после просмотра можно получить именной сертификат
    уже со скидкой.</p>

    <?php foreach ($webinars as $w): ?>
    <div class="competition-card">
        <span class="badge <?php echo $w['badge_class'] ?? ''; ?>"><?php echo htmlspecialchars($w['badge']); ?></span>
        <h3><?php echo htmlspecialchars($w['title']); ?></h3>
        <div class="competition-details">
            <p><strong>Формат:</strong> запись — доступна сразу, смотрите когда удобно</p>
            <p><strong>Документ:</strong> именной сертификат, <?php echo (int)$w['hours']; ?> ак. часа</p>
        </div>
        <div class="price-tag">
            <span style="text-decoration: line-through; color: #94a3b8; font-size: 0.8em;"><?php echo $fmtPrice($w['price']); ?> &#8381;</span>
            &nbsp;<?php echo $fmtPrice($w['price_discounted']); ?> &#8381;
            <small>за сертификат со скидкой &minus;<?php echo (int)$discount_percent; ?>%</small>
        </div>
        <p style="margin: 14px 0 0 0;">
            <a href="<?php echo htmlspecialchars($w['url']); ?>" style="font-weight: 600;">Смотреть вебинар &rarr;</a>
        </p>
    </div>
    <?php endforeach; ?>

    <div class="text-center">
        <a href="<?php echo htmlspecialchars($catalog_url); ?>" class="cta-button">
            Все вебинары со скидкой
        </a>
    </div>

    <p class="text-muted text-small">Ссылки из письма откроют сайт сразу под вашим аккаунтом —
    скидка применится при оплате автоматически.</p>

    <p style="margin-top: 25px;"><?php echo htmlspecialchars($sender_signature); ?></p>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/_base_layout.php';

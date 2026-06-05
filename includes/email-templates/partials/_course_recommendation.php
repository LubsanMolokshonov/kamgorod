<?php
/**
 * Блок рекомендации курсов (ПП → КПК) для первого письма цепочек мероприятий.
 *
 * Цель: участник мероприятия — будущий покупатель курса. Показываем подобранные
 * по его аудитории курс переподготовки и повышения квалификации.
 *
 * Ожидаемые переменные (через extract в шаблоне):
 *   $pp_course           — ['title','slug','price','hours','url'] | null  (переподготовка)
 *   $kpk_course          — ['title','slug','price','hours','url'] | null  (повышение квалиф.)
 *   $course_block_style  — 'personal' (текстовый, для _personal_layout) |
 *                          'card' (карточки, для _webinar_base_layout). По умолчанию 'personal'.
 *
 * Порядок всегда: сначала ПП, потом КПК.
 */

if (empty($pp_course) && empty($kpk_course)) {
    return; // нечего показывать
}

$style = $course_block_style ?? 'personal';

$courseRows = [];
if (!empty($pp_course))  { $courseRows[] = ['label' => 'Профессиональная переподготовка', 'c' => $pp_course]; }
if (!empty($kpk_course)) { $courseRows[] = ['label' => 'Повышение квалификации',        'c' => $kpk_course]; }

$fmtPrice = static function ($price) {
    return number_format((float)$price, 0, '', ' ');
};

if ($style === 'card'):
    // Стиль карточек — для _webinar_base_layout.php (используем его же классы/палитру).
?>
<div class="webinar-card" style="border-left:4px solid #18b89a;">
    <h3 style="color:#0e9a82;">Программы обучения для вашего направления</h3>
    <p class="text-muted" style="margin:0 0 6px;font-size:15px;">
        Документ установленного образца, дистанционно, в удобном темпе.
    </p>
    <?php foreach ($courseRows as $row): $c = $row['c']; ?>
        <div style="padding:16px 0;border-top:1px solid #eceef6;">
            <span class="badge" style="background:#d8f5ee;color:#0e9a82;"><?php echo htmlspecialchars($row['label']); ?></span>
            <div style="font-weight:600;color:#0e1330;font-size:16px;margin:6px 0;">
                <?php echo htmlspecialchars($c['title']); ?>
            </div>
            <div class="text-muted" style="font-size:14px;margin-bottom:12px;">
                <?php echo (int)$c['hours']; ?> ч &middot; <?php echo $fmtPrice($c['price']); ?> ₽
            </div>
            <a href="<?php echo htmlspecialchars($c['url']); ?>" class="cta-button cta-button-secondary" style="margin:0;padding:12px 28px;">
                Подробнее о курсе
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php
else:
    // Личный текстовый стиль — для _personal_layout.php (без кнопок/баннеров).
?>
<p style="margin-top:24px;">Программы обучения с документом установленного образца для вашего направления:</p>
<ul style="margin:0 0 14px;padding-left:24px;">
    <?php foreach ($courseRows as $row): $c = $row['c']; ?>
        <li style="margin-bottom:10px;">
            <?php echo htmlspecialchars($row['label']); ?>:
            <a href="<?php echo htmlspecialchars($c['url']); ?>"><?php echo htmlspecialchars($c['title']); ?></a>
            — <?php echo (int)$c['hours']; ?> ч, <?php echo $fmtPrice($c['price']); ?> ₽.
        </li>
    <?php endforeach; ?>
</ul>
<?php
endif;

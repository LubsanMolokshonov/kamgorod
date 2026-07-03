<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Отчёт «Шеринг публикаций».
 * Воронка: клик по кнопке «поделиться» (publication_shares)
 * → переход по расшаренной ссылке (visits, utm_campaign=publication_share).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/PublicationShareAnalytics.php';

$pageTitle = 'Шеринг публикаций';

// Фильтр периода: date_from / date_to (default — с начала текущего месяца)
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) $dateFrom = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo))   $dateTo = date('Y-m-d');
if ($dateFrom > $dateTo) { [$dateFrom, $dateTo] = [$dateTo, $dateFrom]; }

$startDate = $dateFrom . ' 00:00:00';
$endDate   = $dateTo . ' 23:59:59';

$analytics = new PublicationShareAnalytics($db);

$clicksByNetwork = $analytics->getClicksByNetwork($startDate, $endDate);
$visitsBySource  = $analytics->getVisitsBySource($startDate, $endDate);
$sharedPubs      = $analytics->getSharedPublicationsCount($startDate, $endDate);
$placements      = $analytics->getVisitsByPlacement($startDate, $endDate);
$topPublications = $analytics->getTopPublicationsByClicks($startDate, $endDate);
$visitsBySlug    = $analytics->getVisitsByPublication($startDate, $endDate);
$monthly         = $analytics->getMonthlyDynamics(12);

$totalClicks = array_sum($clicksByNetwork);
$totalVisits = array_sum($visitsBySource);
$totalCr = $totalClicks > 0 ? round($totalVisits / $totalClicks * 100, 1) : 0;

// Строки воронки по сетям: клики ↔ переходы через маппинг network → utm_source
$networkLabels = [
    'vk'       => 'ВКонтакте',
    'telegram' => 'Telegram',
    'whatsapp' => 'WhatsApp',
    'ok'       => 'Одноклассники',
    'copy'     => 'Копирование ссылки',
    'native'   => 'Нативный шеринг (моб.)',
];
$networkRows = [];
foreach (PublicationShareAnalytics::NETWORK_TO_SOURCE as $network => $source) {
    $clicks = $clicksByNetwork[$network] ?? 0;
    $visits = $visitsBySource[$source] ?? 0;
    $networkRows[] = [
        'label'  => $networkLabels[$network] ?? $network,
        'clicks' => $clicks,
        'visits' => $visits,
        'cr'     => $clicks > 0 ? round($visits / $clicks * 100, 1) : null,
    ];
}

$placementLabels = [
    'publication' => 'Страница публикации',
    'cabinet'     => 'Личный кабинет',
    'certificate' => 'Экран свидетельства',
    'email'       => 'Письмо (email)',
];

// Топ публикаций: клики + переходы; публикации с переходами, но без кликов — отдельными строками
$topRows = [];
$seenSlugs = [];
foreach ($topPublications as $pub) {
    $slug = (string)($pub['slug'] ?? '');
    $seenSlugs[$slug] = true;
    $topRows[] = [
        'title'  => $pub['title'],
        'slug'   => $slug,
        'clicks' => (int)$pub['clicks'],
        'breakdown' => sprintf(
            'VK %d · TG %d · WA %d · OK %d · копия %d · натив %d',
            $pub['vk_clicks'], $pub['tg_clicks'], $pub['wa_clicks'],
            $pub['ok_clicks'], $pub['copy_clicks'], $pub['native_clicks']
        ),
        'visits' => $slug !== '' ? ($visitsBySlug[$slug] ?? 0) : 0,
    ];
}
$missingSlugs = array_keys(array_diff_key($visitsBySlug, $seenSlugs));
if (!empty($missingSlugs)) {
    $missingInfo = $analytics->getPublicationsBySlugs($missingSlugs);
    foreach ($missingSlugs as $slug) {
        $topRows[] = [
            'title'  => $missingInfo[$slug]['title'] ?? $slug,
            'slug'   => $slug,
            'clicks' => 0,
            'breakdown' => '',
            'visits' => $visitsBySlug[$slug],
        ];
    }
}

$monthNamesShort = [
    '01' => 'янв', '02' => 'фев', '03' => 'мар', '04' => 'апр',
    '05' => 'май', '06' => 'июн', '07' => 'июл', '08' => 'авг',
    '09' => 'сен', '10' => 'окт', '11' => 'ноя', '12' => 'дек',
];

include __DIR__ . '/../includes/header.php';
?>

<style>
.period-filter {
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 32px; padding: 16px 24px;
    background: white; border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.period-filter label { font-size: 13px; color: #64748b; font-weight: 500; }
.period-filter input[type="date"] {
    padding: 8px 12px; border: 1px solid #e2e8f0; border-radius: 8px; font-size: 14px;
}
.kpi-grid-shares {
    display: grid; grid-template-columns: repeat(4, 1fr);
    gap: 16px; margin-bottom: 24px;
}
.kpi-value-highlight {
    font-size: 28px; font-weight: 800; color: #1e293b; line-height: 1.2;
}
.kpi-label {
    font-size: 13px; color: #64748b; margin-top: 4px; font-weight: 500;
}
.daily-table { width: 100%; border-collapse: collapse; }
.daily-table th, .daily-table td {
    padding: 10px 12px; text-align: right;
    border-bottom: 1px solid #f1f5f9; font-size: 13px;
}
.daily-table th {
    background: #f8fafc; font-weight: 600; color: #475569;
    font-size: 12px; text-transform: uppercase; letter-spacing: .5px;
}
.daily-table th:first-child, .daily-table td:first-child { text-align: left; }
.daily-table tbody tr:hover { background: #f8fafc; }
.daily-table .num-zero { color: #cbd5e1; }
.table-note {
    padding: 12px 24px; font-size: 12px; color: #94a3b8;
    border-top: 1px solid #f1f5f9;
}
.pub-title-link { color: #1e3aa8; text-decoration: none; }
.pub-title-link:hover { text-decoration: underline; }
.clicks-breakdown { font-size: 11px; color: #94a3b8; }
@media (max-width: 1024px) {
    .kpi-grid-shares { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-header">
    <h1>📤 Шеринг публикаций</h1>
    <p>Клики «поделиться» и переходы по расшаренным ссылкам (utm_campaign=publication_share)</p>
</div>

<form method="get" class="period-filter">
    <label>Период с</label>
    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom, ENT_QUOTES, 'UTF-8'); ?>">
    <label>по</label>
    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo, ENT_QUOTES, 'UTF-8'); ?>">
    <button type="submit" class="btn btn-primary btn-sm">Применить</button>
    <a href="?date_from=2026-06-01&date_to=<?php echo date('Y-m-d'); ?>" class="btn btn-secondary btn-sm">Всё время</a>
</form>

<!-- KPI -->
<div class="kpi-grid-shares">
    <div class="stat-card">
        <div class="kpi-value-highlight"><?php echo number_format($totalClicks, 0, ',', ' '); ?></div>
        <div class="kpi-label">Кликов «поделиться»</div>
    </div>
    <div class="stat-card">
        <div class="kpi-value-highlight"><?php echo number_format($totalVisits, 0, ',', ' '); ?></div>
        <div class="kpi-label">Переходов по ссылкам</div>
    </div>
    <div class="stat-card">
        <div class="kpi-value-highlight"><?php echo $totalCr; ?>%</div>
        <div class="kpi-label">CR клик → переход</div>
    </div>
    <div class="stat-card">
        <div class="kpi-value-highlight"><?php echo number_format($sharedPubs, 0, ',', ' '); ?></div>
        <div class="kpi-label">Публикаций шерили</div>
    </div>
</div>

<!-- Воронка по сетям -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2>Воронка по сетям</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if ($totalClicks === 0 && $totalVisits === 0): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📤</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет ни кликов, ни переходов</p>
            </div>
        <?php else: ?>
            <table class="daily-table">
                <thead>
                    <tr>
                        <th>Канал</th>
                        <th>Клики «поделиться»</th>
                        <th>Переходы</th>
                        <th>CR</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($networkRows as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="<?php echo $row['clicks'] === 0 ? 'num-zero' : ''; ?>"><?php echo number_format($row['clicks'], 0, ',', ' '); ?></td>
                            <td class="<?php echo $row['visits'] === 0 ? 'num-zero' : ''; ?>"><strong><?php echo number_format($row['visits'], 0, ',', ' '); ?></strong></td>
                            <td class="<?php echo ($row['cr'] === null || $row['cr'] == 0) ? 'num-zero' : ''; ?>"><?php echo $row['cr'] !== null ? $row['cr'] . '%' : '—'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div class="table-note">
                CR может быть больше 100% — одна расшаренная ссылка приносит много переходов.
                Клики до релиза UTM-разметки переходов не дают. Переходы из письма учитываются
                в своих сетях (VK/TG/WA/OK), клик по кнопке в письме не фиксируется.
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Откуда шерят -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2>Откуда шерят (переходы по utm_content)</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($placements)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📍</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет переходов по расшаренным ссылкам</p>
            </div>
        <?php else: ?>
            <table class="daily-table">
                <thead>
                    <tr>
                        <th>Размещение виджета</th>
                        <th>Переходы</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($placements as $row): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($placementLabels[$row['placement']] ?? $row['placement'], ENT_QUOTES, 'UTF-8'); ?></td>
                            <td><strong><?php echo number_format((int)$row['visits'], 0, ',', ' '); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Топ публикаций -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h2>Топ публикаций</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($topRows)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📄</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде публикациями не делились</p>
            </div>
        <?php else: ?>
            <table class="daily-table">
                <thead>
                    <tr>
                        <th>Публикация</th>
                        <th>Клики «поделиться»</th>
                        <th>Переходы</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($topRows as $row): ?>
                        <tr>
                            <td>
                                <?php if (!empty($row['slug'])): ?>
                                    <a class="pub-title-link" href="/publikaciya/<?php echo htmlspecialchars($row['slug'], ENT_QUOTES, 'UTF-8'); ?>/" target="_blank" rel="noopener"><?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?></a>
                                <?php else: ?>
                                    <?php echo htmlspecialchars($row['title'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php endif; ?>
                                <?php if ($row['breakdown'] !== ''): ?>
                                    <div class="clicks-breakdown"><?php echo htmlspecialchars($row['breakdown'], ENT_QUOTES, 'UTF-8'); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="<?php echo $row['clicks'] === 0 ? 'num-zero' : ''; ?>"><?php echo number_format($row['clicks'], 0, ',', ' '); ?></td>
                            <td class="<?php echo $row['visits'] === 0 ? 'num-zero' : ''; ?>"><strong><?php echo number_format($row['visits'], 0, ',', ' '); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Динамика по месяцам -->
<div class="content-card">
    <div class="card-header">
        <h2>Динамика по месяцам (12 мес., независимо от фильтра)</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($monthly)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h3>Нет данных</h3>
                <p>Шеринг ещё не использовался</p>
            </div>
        <?php else: ?>
            <table class="daily-table">
                <thead>
                    <tr>
                        <th>Месяц</th>
                        <th>Клики «поделиться»</th>
                        <th>Переходы</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_reverse($monthly) as $row):
                        [$y, $m] = explode('-', $row['ym']);
                        $label = ($monthNamesShort[$m] ?? $m) . ' ' . $y;
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></td>
                            <td class="<?php echo $row['clicks'] === 0 ? 'num-zero' : ''; ?>"><?php echo number_format($row['clicks'], 0, ',', ' '); ?></td>
                            <td class="<?php echo $row['visits'] === 0 ? 'num-zero' : ''; ?>"><strong><?php echo number_format($row['visits'], 0, ',', ' '); ?></strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

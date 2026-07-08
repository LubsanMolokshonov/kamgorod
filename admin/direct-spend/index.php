<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Расходы Яндекс.Директа — отчёт по кампаниям из direct_ad_spend
 * (синк из ai.h1pro.ru, cron/sync-direct-spend.php). Только просмотр.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/DirectionAnalytics.php';

$pageTitle = 'Расходы Директа';
$additionalCSS = ['/assets/css/admin-rnp.css?v=' . filemtime(__DIR__ . '/../../assets/css/admin-rnp.css')];

$database = new Database($db);

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-29 days'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
foreach (['dateFrom', 'dateTo'] as $v) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) {
        $$v = date('Y-m-d');
    }
}

$directionLabels = DirectionAnalytics::DIRECTIONS + ['other' => 'Не распределено'];
$sectionLabels = ['portal' => 'Портал', 'course' => 'Курсы'];

// Сводка по кампаниям
$campaigns = $database->query(
    "SELECT campaign_id, campaign_name, direction, section,
            SUM(cost) AS total, MIN(date) AS first_date, MAX(date) AS last_date,
            COUNT(*) AS days
     FROM direct_ad_spend
     WHERE date BETWEEN ? AND ?
     GROUP BY campaign_id, campaign_name, direction, section
     ORDER BY total DESC",
    [$dateFrom, $dateTo]
);

// Итоги по направлениям и секциям
$byDirection = $database->query(
    "SELECT direction, SUM(cost) AS total FROM direct_ad_spend
     WHERE date BETWEEN ? AND ? GROUP BY direction ORDER BY total DESC",
    [$dateFrom, $dateTo]
);
$bySection = $database->query(
    "SELECT section, SUM(cost) AS total FROM direct_ad_spend
     WHERE date BETWEEN ? AND ? GROUP BY section",
    [$dateFrom, $dateTo]
);

$grandTotal = 0.0;
foreach ($campaigns as $c) {
    $grandTotal += (float)$c['total'];
}

$lastSync = $database->queryOne("SELECT MAX(synced_at) AS ts FROM direct_ad_spend");

function dsMoney($v): string {
    return number_format((float)$v, 0, ',', ' ') . ' ₽';
}
function dsPct(float $part, float $whole): string {
    if ($whole <= 0) return '—';
    return number_format($part / $whole * 100, 1, ',', ' ') . '%';
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.ds-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.ds-table th, .ds-table td { padding: 8px 10px; border-bottom: 1px solid #eef0f3; text-align: left; }
.ds-table th { font-weight: 600; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
.ds-table td.num, .ds-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.ds-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #eef2ff; color: #4338ca; }
.ds-badge--other { background: #fee2e2; color: #b91c1c; }
.ds-badge--section { background: #f1f5f9; color: #475569; }
.ds-summary { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 20px; }
tr.ds-row-other td { background: #fef2f2; }
</style>

<div class="page-header">
    <h1>Расходы Директа</h1>
    <p class="page-sub">
        Синк из ai.h1pro.ru (аккаунт direktfgos), суммы с НДС —
        <?= htmlspecialchars($dateFrom) ?> — <?= htmlspecialchars($dateTo) ?>.
        <?php if (!empty($lastSync['ts'])): ?>
            Последняя синхронизация: <?= htmlspecialchars($lastSync['ts']) ?>
        <?php else: ?>
            Синхронизаций ещё не было — запустите cron/sync-direct-spend.php
        <?php endif; ?>
    </p>
</div>

<div class="content-card rnp-filters">
    <form method="GET">
        <div class="filter-row">
            <div class="filter-group">
                <label>Период</label>
                <div class="filter-dates">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <span>—</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
            </div>
        </div>
    </form>
</div>

<div class="ds-summary">
    <div class="content-card rnp-card">
        <div class="rnp-card-header"><h2>По направлениям</h2></div>
        <table class="ds-table">
            <thead><tr><th>Направление</th><th class="num">Расход</th><th class="num">Доля</th></tr></thead>
            <tbody>
            <?php foreach ($byDirection as $d): ?>
                <tr<?= $d['direction'] === 'other' ? ' class="ds-row-other"' : '' ?>>
                    <td><?= htmlspecialchars($directionLabels[$d['direction']] ?? $d['direction']) ?></td>
                    <td class="num"><?= dsMoney($d['total']) ?></td>
                    <td class="num"><?= dsPct((float)$d['total'], $grandTotal) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><strong>Итого</strong></td>
                <td class="num"><strong><?= dsMoney($grandTotal) ?></strong></td>
                <td class="num">100%</td>
            </tr>
            </tbody>
        </table>
    </div>

    <div class="content-card rnp-card">
        <div class="rnp-card-header"><h2>Портал / Курсы (как в РНП)</h2></div>
        <table class="ds-table">
            <thead><tr><th>Секция</th><th class="num">Расход</th><th class="num">Доля</th></tr></thead>
            <tbody>
            <?php foreach ($bySection as $s): ?>
                <tr>
                    <td><?= htmlspecialchars($sectionLabels[$s['section']] ?? $s['section']) ?></td>
                    <td class="num"><?= dsMoney($s['total']) ?></td>
                    <td class="num"><?= dsPct((float)$s['total'], $grandTotal) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="content-card rnp-card">
    <div class="rnp-card-header">
        <h2>Кампании</h2>
        <span class="rnp-card-meta">
            Направление определяется по названию кампании; «Не распределено» — проверьте название в Директе
        </span>
    </div>
    <?php if (!$campaigns): ?>
        <p style="color:#6b7280;padding:8px 4px;">Нет данных за выбранный период.</p>
    <?php else: ?>
    <table class="ds-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Кампания</th>
                <th>Направление</th>
                <th>Секция</th>
                <th class="num">Дней</th>
                <th class="num">Расход</th>
                <th class="num">Доля</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($campaigns as $c): ?>
            <tr<?= $c['direction'] === 'other' ? ' class="ds-row-other"' : '' ?>>
                <td><?= (int)$c['campaign_id'] ?></td>
                <td><?= htmlspecialchars($c['campaign_name']) ?></td>
                <td>
                    <span class="ds-badge<?= $c['direction'] === 'other' ? ' ds-badge--other' : '' ?>">
                        <?= htmlspecialchars($directionLabels[$c['direction']] ?? $c['direction']) ?>
                    </span>
                </td>
                <td><span class="ds-badge ds-badge--section"><?= htmlspecialchars($sectionLabels[$c['section']] ?? $c['section']) ?></span></td>
                <td class="num"><?= (int)$c['days'] ?></td>
                <td class="num"><?= dsMoney($c['total']) ?></td>
                <td class="num"><?= dsPct((float)$c['total'], $grandTotal) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

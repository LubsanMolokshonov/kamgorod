<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Экономика направлений — понедельный дашборд по продуктам.
 * Транспонированная таблица: строки = направление × метрики, столбцы = Итого → недели.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/DirectionAnalytics.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Экономика направлений';
$additionalCSS = ['/assets/css/admin-rnp.css?v=' . filemtime(__DIR__ . '/../../assets/css/admin-rnp.css')];
$additionalJS = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
    '/assets/js/admin-directions.js?v=' . filemtime(__DIR__ . '/../../assets/js/admin-directions.js'),
];

$analytics = new DirectionAnalytics($db);

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

$report = $analytics->getReport($dateFrom, $dateTo);
$csrfToken = generateCSRFToken();

// ============================================================
// Столбцы: Итого → недели
// ============================================================
$columns = [];
$columns[] = [
    'type'  => 'total',
    'label' => 'Итого',
    'rows'  => $report['grand_rows'],
    'total' => $report['grand_total'],
    'date'  => null,
];
foreach ($report['periods'] as $w) {
    $columns[] = [
        'type'  => 'week',
        'label' => $w['label'],
        'rows'  => $w['rows'],
        'total' => $w['total'],
        'date'  => $w['start'], // понедельник недели — ключ для ввода расхода
    ];
}

// ============================================================
// Группы строк: ОБЩИЙ ИТОГ + направления
// ============================================================
$groups = [
    ['key' => '__total__', 'label' => 'ОБЩИЙ ИТОГ', 'is_sum' => true, 'editable' => false],
];
foreach (DirectionAnalytics::DIRECTIONS as $dirKey => $dirLabel) {
    $groups[] = ['key' => $dirKey, 'label' => $dirLabel, 'is_sum' => false, 'editable' => true];
}

$metrics = [
    ['key' => 'cost',      'label' => 'Расход',            'format' => 'money', 'editable' => true],
    ['key' => 'revenue',   'label' => 'Выручка',           'format' => 'money'],
    ['key' => 'payments',  'label' => 'Оплаты',            'format' => 'int'],
    ['key' => 'avg_check', 'label' => 'Средний чек',       'format' => 'money'],
    ['key' => 'cpa',       'label' => 'CPA',               'format' => 'money'],
    ['key' => 'drr',       'label' => 'ДРР',               'format' => 'pct',   'highlight_drr' => true],
    ['key' => 'profit',    'label' => 'Маркетинг. прибыль', 'format' => 'money', 'highlight' => true],
    ['key' => 'romi',      'label' => 'ROMI',              'format' => 'pct'],
];

// ============================================================
// Предрасчёт: [groupKey][colIndex] => metrics
// ============================================================
$grid = [];
foreach ($columns as $ci => $col) {
    foreach ($groups as $grp) {
        $grid[$grp['key']][$ci] = ($grp['key'] === '__total__')
            ? $col['total']
            : $col['rows'][$grp['key']];
    }
}

// ============================================================
// Форматирование
// ============================================================
function dirMoney($v): string {
    if ($v === null) return '—';
    $f = (float)$v;
    if ($f == 0) return '0';
    return number_format($f, 0, ',', ' ');
}
function dirInt($v): string {
    if ($v === null) return '—';
    $f = (float)$v;
    return $f == (int)$f ? number_format($f, 0, ',', ' ') : number_format($f, 1, ',', ' ');
}
function dirPct($v): string {
    if ($v === null) return '—';
    return number_format((float)$v * 100, 1, ',', ' ') . '%';
}
function dirFormat(string $fmt, $v): string {
    switch ($fmt) {
        case 'money': return dirMoney($v);
        case 'int':   return dirInt($v);
        case 'pct':   return dirPct($v);
        default:      return (string)$v;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Экономика направлений</h1>
    <p class="page-sub">Понедельная экономика по продуктам — <?= htmlspecialchars($dateFrom) ?> — <?= htmlspecialchars($dateTo) ?></p>
</div>

<div class="content-card rnp-filters">
    <form id="dirFilterForm" method="GET">
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

<!-- Основная таблица -->
<div class="content-card rnp-card">
    <div class="rnp-card-header">
        <h2>Направления по неделям</h2>
        <span class="rnp-card-meta">Вводите расход прочих каналов (ВК и др.) — Директ подтягивается автоматически из ai.h1pro.ru</span>
    </div>
    <div class="rnp-pivot-wrapper">
        <table class="rnp-pivot">
            <thead>
                <tr>
                    <th class="rnp-pivot-sticky rnp-pivot-sticky-group">Направление</th>
                    <th class="rnp-pivot-sticky rnp-pivot-sticky-metric">Метрика</th>
                    <?php foreach ($columns as $col): ?>
                    <th class="rnp-pivot-col rnp-pivot-col-<?= $col['type'] ?>">
                        <?= htmlspecialchars($col['label']) ?>
                    </th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($groups as $grp):
                    $metricCount = count($metrics);
                ?>
                <?php foreach ($metrics as $mi => $metric):
                    $isFirst = ($mi === 0);
                    $isLast  = ($mi === $metricCount - 1);
                    $isCost  = ($metric['key'] === 'cost');
                    $isProfit = ($metric['key'] === 'profit');
                    $isEditable = !empty($metric['editable']) && !empty($grp['editable']);
                ?>
                <tr class="rnp-pivot-row<?= $isFirst ? ' rnp-pivot-group-first' : '' ?><?= $isLast ? ' rnp-pivot-group-last' : '' ?><?= $grp['is_sum'] ? ' rnp-pivot-sum-group' : '' ?>">
                    <?php if ($isFirst): ?>
                    <td class="rnp-pivot-sticky rnp-pivot-sticky-group rnp-pivot-group-label" rowspan="<?= $metricCount ?>">
                        <?= htmlspecialchars($grp['label']) ?>
                    </td>
                    <?php endif; ?>
                    <td class="rnp-pivot-sticky rnp-pivot-sticky-metric"><?= htmlspecialchars($metric['label']) ?></td>
                    <?php foreach ($columns as $ci => $col):
                        $cell = $grid[$grp['key']][$ci];
                        $val = $cell[$metric['key']] ?? null;
                        $isWeek = ($col['type'] === 'week');
                        $cssExtra = '';
                        if ($isProfit && $val !== null) {
                            $cssExtra = $val < 0 ? ' is-negative' : ($val > 0 ? ' is-positive' : '');
                        }
                        if (!empty($metric['highlight_drr']) && $val !== null) {
                            // ДРР: <50% — хорошо, >100% — убыточно
                            if ($val > 1)        $cssExtra = ' is-negative';
                            elseif ($val <= 0.5) $cssExtra = ' is-positive';
                        }
                    ?>
                    <td class="rnp-pivot-val rnp-pivot-col-<?= $col['type'] ?><?= $isCost ? ' rnp-cost-cell' : '' ?><?= $cssExtra ?>">
                        <?php if ($isCost && $isEditable && $isWeek): ?>
                            <input
                                type="number"
                                step="1"
                                min="0"
                                class="rnp-cost-input dir-cost-input"
                                data-week-start="<?= htmlspecialchars($col['date']) ?>"
                                data-direction="<?= htmlspecialchars($grp['key']) ?>"
                                value="<?= $cell['cost_manual'] > 0 ? (int)round($cell['cost_manual']) : '' ?>"
                                placeholder="0"
                                title="Ручной ввод: расход прочих каналов (без Директа)"
                            >
                            <?php if (($cell['cost_direct'] ?? 0) > 0): ?>
                            <span class="rnp-cost-auto-note" title="Директ — автоматически из ai.h1pro.ru">+<?= dirMoney($cell['cost_direct']) ?> Директ</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= dirFormat($metric['format'], $val) ?>
                        <?php endif; ?>
                    </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Графики -->
<div class="content-card rnp-card">
    <div class="rnp-card-header">
        <h2>Графики по неделям</h2>
    </div>
    <div class="rnp-charts">
        <div class="rnp-chart-box">
            <h3>Выручка vs Расход</h3>
            <canvas id="dirChartRevenueCost"></canvas>
        </div>
        <div class="rnp-chart-box">
            <h3>Маркетинговая прибыль</h3>
            <canvas id="dirChartProfit"></canvas>
        </div>
    </div>
</div>

<script>
window.DIR_DATA = <?= json_encode([
    'csrf' => $csrfToken,
    'chart' => [
        'labels'  => array_map(fn($p) => $p['label'], $report['periods']),
        'revenue' => array_map(fn($p) => round($p['total']['revenue'], 2), $report['periods']),
        'cost'    => array_map(fn($p) => round($p['total']['cost'], 2), $report['periods']),
        'profit'  => array_map(fn($p) => round($p['total']['profit'], 2), $report['periods']),
    ],
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

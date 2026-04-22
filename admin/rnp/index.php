<?php
/**
 * РНП (Рука на пульсе) — маркетинговый дашборд.
 * Транспонированная таблица: строки = каналы × метрики, столбцы = Итого → недели → дни.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/RNPAnalytics.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'РНП — Рука на пульсе';
$additionalCSS = ['/assets/css/admin-rnp.css?v=' . filemtime(__DIR__ . '/../../assets/css/admin-rnp.css')];
$additionalJS = [
    'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
    '/assets/js/admin-rnp.js?v=' . filemtime(__DIR__ . '/../../assets/js/admin-rnp.js'),
];

$rnp = new RNPAnalytics($db);

$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');

// Всегда получаем оба уровня — дни и недели
$dayReport  = $rnp->getReport($dateFrom, $dateTo, 'day');
$weekReport = $rnp->getReport($dateFrom, $dateTo, 'week');

$csrfToken = generateCSRFToken();

// ============================================================
// Построение столбцов: Итого → недели → дни
// ============================================================
$columns = [];

// 1. Итого
$columns[] = [
    'type'  => 'total',
    'label' => 'Итого',
    'sub'   => '',
    'rows'  => $dayReport['grand_rows'],
    'total' => $dayReport['grand_total'],
    'date'  => null,
];

// 2. Недели
foreach ($weekReport['periods'] as $w) {
    $columns[] = [
        'type'  => 'week',
        'label' => $w['label'],
        'sub'   => '',
        'rows'  => $w['rows'],
        'total' => $w['total'],
        'date'  => null,
    ];
}

// 3. Дни
foreach ($dayReport['periods'] as $d) {
    $columns[] = [
        'type'  => 'day',
        'label' => $d['label'],
        'sub'   => '',
        'rows'  => $d['rows'],
        'total' => $d['total'],
        'date'  => $d['key'],
    ];
}

// ============================================================
// Группы строк (каналы)
// ============================================================

/**
 * Суммировать метрики по нескольким channel×section ячейкам.
 */
function rnpSumChannels(array $rows, array $channels): array {
    $s = ['cost'=>0,'revenue'=>0,'payments'=>0,'created_orders'=>0,'paid_orders'=>0,'leads'=>0];
    $hasCourse = false;
    foreach ($channels as [$ch, $sec]) {
        $c = $rows[$ch][$sec];
        $s['cost']           += $c['cost'];
        $s['revenue']        += $c['revenue'];
        $s['payments']       += $c['payments'];
        $s['created_orders'] += $c['created_orders'];
        $s['paid_orders']    += $c['paid_orders'];
        $s['leads']          += $c['leads'] ?? 0;
        if ($sec === 'course') $hasCourse = true;
    }
    $s['cpa']        = $s['payments'] > 0 ? $s['cost'] / $s['payments'] : null;
    $s['avg_check']  = $s['payments'] > 0 ? $s['revenue'] / $s['payments'] : null;
    $s['profit']     = $s['revenue'] - $s['cost'];
    $s['romi']       = $s['cost'] > 0 ? ($s['revenue'] - $s['cost']) / $s['cost'] : null;
    $s['conversion'] = $s['created_orders'] > 0 ? $s['paid_orders'] / $s['created_orders'] : null;
    $s['lead_cost']  = ($hasCourse && $s['leads'] > 0) ? $s['cost'] / $s['leads'] : null;
    $s['_has_course'] = $hasCourse;
    return $s;
}

$groups = [
    [
        'key' => 'grand_total', 'label' => 'ОБЩИЙ ИТОГ', 'is_sum' => true,
        'channels' => [
            ['direct','portal'],['vk','portal'],['other','portal'],
            ['direct','course'],['vk','course'],['other','course'],
        ],
        'cost_field' => null,
    ],
    [
        'key' => 'total_portal', 'label' => 'ИТОГО ПОРТАЛ', 'is_sum' => true,
        'channels' => [['direct','portal'],['vk','portal'],['other','portal']],
        'cost_field' => null,
    ],
    [
        'key' => 'direct_portal', 'label' => 'Пед.портал Директ', 'is_sum' => false,
        'channels' => [['direct','portal']],
        'cost_field' => 'direct_portal_cost',
    ],
    [
        'key' => 'vk_portal', 'label' => 'Пед.портал ВК', 'is_sum' => false,
        'channels' => [['vk','portal']],
        'cost_field' => 'vk_portal_cost',
    ],
    [
        'key' => 'other_portal', 'label' => 'Пед.портал Другое', 'is_sum' => false,
        'channels' => [['other','portal']],
        'cost_field' => 'other_portal_cost',
    ],
    [
        'key' => 'total_course', 'label' => 'ИТОГО КУРСЫ', 'is_sum' => true,
        'channels' => [['direct','course'],['vk','course'],['other','course']],
        'cost_field' => null,
    ],
    [
        'key' => 'direct_course', 'label' => 'Курсы Директ', 'is_sum' => false,
        'channels' => [['direct','course']],
        'cost_field' => 'direct_course_cost',
    ],
    [
        'key' => 'vk_course', 'label' => 'Курсы ВК', 'is_sum' => false,
        'channels' => [['vk','course']],
        'cost_field' => 'vk_course_cost',
    ],
    [
        'key' => 'other_course', 'label' => 'Курсы Другое', 'is_sum' => false,
        'channels' => [['other','course']],
        'cost_field' => 'other_course_cost',
    ],
    [
        'key' => 'total_direct', 'label' => 'ИТОГО ДИРЕКТ', 'is_sum' => true,
        'channels' => [['direct','portal'],['direct','course']],
        'cost_field' => null,
    ],
    [
        'key' => 'total_vk', 'label' => 'ИТОГО ВК', 'is_sum' => true,
        'channels' => [['vk','portal'],['vk','course']],
        'cost_field' => null,
    ],
];

$metrics = [
    ['key' => 'cost',     'label' => 'Расход',           'format' => 'money', 'editable' => true],
    ['key' => 'leads',    'label' => 'Заявки',           'format' => 'int',   'course_only' => true],
    ['key' => 'lead_cost','label' => 'Цена заявки',      'format' => 'money', 'course_only' => true],
    ['key' => 'revenue',  'label' => 'Выручка',          'format' => 'money'],
    ['key' => 'payments', 'label' => 'Оплаты',           'format' => 'int'],
    ['key' => 'cpa',      'label' => 'CPA',              'format' => 'money'],
    ['key' => 'avg_check','label' => 'Средний чек',      'format' => 'money'],
    ['key' => 'profit',   'label' => 'Маркетинг. прибыль','format' => 'money', 'highlight' => true],
];

// Флаг «в группе есть курсовый канал» — для отображения метрик «Заявки / Цена заявки».
foreach ($groups as &$__grp) {
    $__grp['has_course'] = false;
    foreach ($__grp['channels'] as [$__ch, $__sec]) {
        if ($__sec === 'course') { $__grp['has_course'] = true; break; }
    }
}
unset($__grp);

// ============================================================
// Предрасчёт: для каждого столбца × каждой группы — значения
// ============================================================
$grid = []; // [groupKey][colIndex] => metrics array
foreach ($columns as $ci => $col) {
    foreach ($groups as $grp) {
        if (count($grp['channels']) === 1) {
            [$ch, $sec] = $grp['channels'][0];
            $grid[$grp['key']][$ci] = $col['rows'][$ch][$sec];
        } else {
            $grid[$grp['key']][$ci] = rnpSumChannels($col['rows'], $grp['channels']);
        }
    }
}

/**
 * Форматирование.
 */
function rnpMoney($v): string {
    if ($v === null || $v === 0 || $v === 0.0) return $v === 0 || $v === 0.0 ? '0 ₽' : '—';
    return number_format((float)$v, 0, ',', ' ') . ' ₽';
}
function rnpMoneyShort($v): string {
    if ($v === null) return '—';
    $f = (float)$v;
    if ($f == 0) return '0';
    return number_format($f, 0, ',', ' ');
}
function rnpInt($v): string {
    if ($v === null) return '—';
    $f = (float)$v;
    return $f == (int)$f ? number_format($f, 0, ',', ' ') : number_format($f, 1, ',', ' ');
}
function rnpPct($v): string {
    if ($v === null) return '—';
    return number_format((float)$v * 100, 1, ',', ' ') . '%';
}
function rnpFormat(string $fmt, $v): string {
    switch ($fmt) {
        case 'money': return rnpMoneyShort($v);
        case 'int':   return rnpInt($v);
        case 'pct':   return rnpPct($v);
        default:      return (string)$v;
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>РНП — Рука на пульсе</h1>
    <p class="page-sub">Каналы × направления, расходы и прибыль — <?= htmlspecialchars($dateFrom) ?> — <?= htmlspecialchars($dateTo) ?></p>
</div>

<div class="content-card rnp-filters">
    <form id="rnpFilterForm" method="GET">
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
        <h2>Аналитика по каналам</h2>
        <span class="rnp-card-meta">Редактируйте расходы прямо в ячейках дневных столбцов</span>
    </div>
    <div class="rnp-pivot-wrapper">
        <table class="rnp-pivot">
            <thead>
                <tr>
                    <th class="rnp-pivot-sticky rnp-pivot-sticky-group">Канал</th>
                    <th class="rnp-pivot-sticky rnp-pivot-sticky-metric">Метрика</th>
                    <?php foreach ($columns as $ci => $col): ?>
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
                    $isEditable = !empty($metric['editable']) && $grp['cost_field'] !== null;
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
                        $isDay = ($col['type'] === 'day');
                        $hideForPortalOnly = !empty($metric['course_only']) && empty($grp['has_course']);
                        $cssExtra = '';
                        if ($isProfit && $val !== null) {
                            $cssExtra = $val < 0 ? ' is-negative' : ($val > 0 ? ' is-positive' : '');
                        }
                    ?>
                    <td class="rnp-pivot-val rnp-pivot-col-<?= $col['type'] ?><?= $isCost ? ' rnp-cost-cell' : '' ?><?= $cssExtra ?>">
                        <?php if ($hideForPortalOnly): ?>
                            —
                        <?php elseif ($isCost && $isEditable && $isDay): ?>
                            <input
                                type="number"
                                step="1"
                                min="0"
                                class="rnp-cost-input"
                                data-date="<?= htmlspecialchars($col['date']) ?>"
                                data-field="<?= htmlspecialchars($grp['cost_field']) ?>"
                                value="<?= $val > 0 ? (int)round($val) : '' ?>"
                                placeholder="0"
                            >
                        <?php else: ?>
                            <?= rnpFormat($metric['format'], $val) ?>
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
        <h2>Графики</h2>
    </div>
    <div class="rnp-charts">
        <div class="rnp-chart-box">
            <h3>Выручка vs Расход</h3>
            <canvas id="rnpChartRevenueCost"></canvas>
        </div>
        <div class="rnp-chart-box">
            <h3>Маркетинговая прибыль</h3>
            <canvas id="rnpChartProfit"></canvas>
        </div>
        <div class="rnp-chart-box">
            <h3>CPA</h3>
            <canvas id="rnpChartCPA"></canvas>
        </div>
    </div>
</div>

<script>
window.RNP_DATA = <?= json_encode([
    'csrf' => $csrfToken,
    'chart' => [
        'labels'  => array_map(fn($p) => $p['label'], $dayReport['periods']),
        'revenue' => array_map(fn($p) => round($p['total']['revenue'], 2), $dayReport['periods']),
        'cost'    => array_map(fn($p) => round($p['total']['cost'], 2), $dayReport['periods']),
        'profit'  => array_map(fn($p) => round($p['total']['profit'], 2), $dayReport['periods']),
        'cpa'     => array_map(fn($p) => $p['total']['cpa'] !== null ? round($p['total']['cpa'], 2) : null, $dayReport['periods']),
    ],
], JSON_UNESCAPED_UNICODE) ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

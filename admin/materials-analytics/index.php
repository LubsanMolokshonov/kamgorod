<?php
/**
 * Аналитика раздела «Материалы ФОП».
 * Воронка: Визиты → Регистрации → Сгенерировало → Токены → Оплаты → Выручка.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/MaterialsAnalytics.php';

$pageTitle = 'Аналитика Материалов ФОП';

// Фильтр по месяцу (как в основном дашборде)
$filterYear = (int)($_GET['year'] ?? date('Y'));
$filterMonth = (int)($_GET['month'] ?? date('n'));
if ($filterMonth < 1 || $filterMonth > 12) $filterMonth = (int)date('n');
if ($filterYear < 2024 || $filterYear > 2030) $filterYear = (int)date('Y');

$startDate = sprintf('%04d-%02d-01 00:00:00', $filterYear, $filterMonth);
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));

$prevMonth = $filterMonth - 1; $prevYear = $filterYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }
$nextMonth = $filterMonth + 1; $nextYear = $filterYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthNames = [
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
    5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
    9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
];
$currentMonthName = $monthNames[$filterMonth] . ' ' . $filterYear;

$analytics = new MaterialsAnalytics($db);
$totals = $analytics->getTotals($startDate, $endDate);
$daily = $analytics->getDailyBreakdown($startDate, $endDate);

include __DIR__ . '/../includes/header.php';
?>

<style>
.month-nav {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 32px; padding: 16px 24px;
    background: white; border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.month-nav-current {
    font-size: 18px; font-weight: 700; color: #2C3E50;
    min-width: 180px; text-align: center;
}
.kpi-grid-funnel {
    display: grid; grid-template-columns: repeat(6, 1fr);
    gap: 16px; margin-bottom: 24px;
}
.kpi-value-highlight {
    font-size: 28px; font-weight: 800; color: #1e293b; line-height: 1.2;
}
.kpi-label {
    font-size: 13px; color: #64748b; margin-top: 4px; font-weight: 500;
}
.kpi-conv {
    font-size: 11px; color: #6d28d9; margin-top: 6px; font-weight: 600;
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
@media (max-width: 1024px) {
    .kpi-grid-funnel { grid-template-columns: repeat(2, 1fr); }
}
</style>

<div class="page-header">
    <h1>📚 Аналитика Материалов ФОП</h1>
    <p>Воронка генератора учебных материалов за <?php echo htmlspecialchars($currentMonthName); ?></p>
</div>

<div class="month-nav">
    <a href="?month=<?php echo $prevMonth; ?>&year=<?php echo $prevYear; ?>" class="btn btn-secondary btn-sm">
        &larr; <?php echo $monthNames[$prevMonth]; ?>
    </a>
    <span class="month-nav-current"><?php echo htmlspecialchars($currentMonthName); ?></span>
    <a href="?month=<?php echo $nextMonth; ?>&year=<?php echo $nextYear; ?>" class="btn btn-secondary btn-sm">
        <?php echo $monthNames[$nextMonth]; ?> &rarr;
    </a>
    <?php if ($filterMonth != (int)date('n') || $filterYear != (int)date('Y')): ?>
        <a href="?month=<?php echo date('n'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-primary btn-sm" style="margin-left: 12px;">
            Текущий месяц
        </a>
    <?php endif; ?>
</div>

<!-- Воронка -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="kpi-section-header">
        <h2>Воронка раздела</h2>
    </div>
    <div class="kpi-grid-funnel">
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['visits'], 0, ',', ' '); ?></div>
            <div class="kpi-label">Визиты на лендинг</div>
            <div class="kpi-conv">&nbsp;</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['registered'], 0, ',', ' '); ?></div>
            <div class="kpi-label">Зарегистрировалось</div>
            <div class="kpi-conv"><?php echo $totals['conv_visit_to_reg']; ?>% от визитов</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['generated'], 0, ',', ' '); ?></div>
            <div class="kpi-label">Сгенерировало</div>
            <div class="kpi-conv"><?php echo $totals['conv_reg_to_generated']; ?>% от регистраций</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['tokens_spent'], 0, ',', ' '); ?></div>
            <div class="kpi-label">Потрачено токенов</div>
            <div class="kpi-conv">&nbsp;</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['paid'], 0, ',', ' '); ?></div>
            <div class="kpi-label">Оплат пакетов</div>
            <div class="kpi-conv"><?php echo $totals['conv_generated_to_paid']; ?>% от сгенерировавших</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totals['revenue'], 0, ',', ' '); ?> &#8381;</div>
            <div class="kpi-label">Выручка</div>
            <div class="kpi-conv">покупки токенов</div>
        </div>
    </div>
</div>

<!-- Разбивка по дням -->
<div class="content-card">
    <div class="card-header">
        <h2>Разбивка по дням</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($daily)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет активности</p>
            </div>
        <?php else: ?>
            <table class="daily-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Визиты</th>
                        <th>Регистрации</th>
                        <th>Сгенерировало</th>
                        <th>Токены</th>
                        <th>Оплаты</th>
                        <th>Выручка</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($daily as $row): ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($row['date'])); ?></td>
                            <td class="<?php echo $row['visits'] === 0 ? 'num-zero' : ''; ?>"><?php echo $row['visits']; ?></td>
                            <td class="<?php echo $row['registered'] === 0 ? 'num-zero' : ''; ?>"><?php echo $row['registered']; ?></td>
                            <td class="<?php echo $row['generated'] === 0 ? 'num-zero' : ''; ?>"><?php echo $row['generated']; ?></td>
                            <td class="<?php echo $row['tokens_spent'] === 0 ? 'num-zero' : ''; ?>"><?php echo number_format($row['tokens_spent'], 0, ',', ' '); ?></td>
                            <td class="<?php echo $row['paid'] === 0 ? 'num-zero' : ''; ?>"><strong><?php echo $row['paid']; ?></strong></td>
                            <td class="<?php echo $row['revenue'] == 0 ? 'num-zero' : ''; ?>"><?php echo number_format($row['revenue'], 0, ',', ' '); ?> &#8381;</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

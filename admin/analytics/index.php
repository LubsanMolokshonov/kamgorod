<?php
/**
 * UTM-аналитика — отчёт по UTM-меткам
 * Иерархия: Source → Campaign → Content → Term
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/UTMAnalytics.php';

$pageTitle = 'UTM-аналитика';
$additionalCSS = ['/assets/css/admin-analytics.css'];
$additionalJS = ['/assets/js/admin-utm-analytics.js'];

$analytics = new UTMAnalytics($db);

// Параметры фильтров (по умолчанию — текущий месяц)
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-d');
$paidFrom = $_GET['paid_from'] ?? '';
$paidTo = $_GET['paid_to'] ?? '';
$productType = $_GET['product_type'] ?? 'all';

$filters = [
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'paid_from' => $paidFrom,
    'paid_to' => $paidTo,
    'product_type' => $productType,
];

// Начальные данные: итого + source-level
$totals = $analytics->getTotals($filters);
$sourceData = $analytics->getReport($filters, 'source');

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>UTM-аналитика</h1>
</div>

<!-- Фильтры -->
<div class="content-card utm-filters">
    <form id="utmFilterForm" method="GET">
        <div class="filter-row">
            <div class="filter-group">
                <label>Период создания</label>
                <div class="filter-dates">
                    <input type="date" name="date_from" value="<?php echo htmlspecialchars($dateFrom); ?>">
                    <span>—</span>
                    <input type="date" name="date_to" value="<?php echo htmlspecialchars($dateTo); ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Период оплаты</label>
                <div class="filter-dates">
                    <input type="date" name="paid_from" value="<?php echo htmlspecialchars($paidFrom); ?>">
                    <span>—</span>
                    <input type="date" name="paid_to" value="<?php echo htmlspecialchars($paidTo); ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Тип продукта</label>
                <div class="filter-radios">
                    <label class="radio-label">
                        <input type="radio" name="product_type" value="all" <?php echo $productType === 'all' ? 'checked' : ''; ?>>
                        Все
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="product_type" value="pedportal" <?php echo $productType === 'pedportal' ? 'checked' : ''; ?>>
                        Педпортал
                    </label>
                    <label class="radio-label">
                        <input type="radio" name="product_type" value="courses" <?php echo $productType === 'courses' ? 'checked' : ''; ?>>
                        Курсы
                    </label>
                </div>
            </div>

            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
            </div>
        </div>
    </form>
</div>

<!-- Таблица отчёта -->
<div class="content-card utm-report-card">
    <div class="utm-table-wrapper">
        <table class="utm-table" id="utmTable">
            <thead>
                <tr>
                    <th class="col-label">UTM Source › Campaign › Content › Term</th>
                    <th class="col-num">Визиты</th>
                    <th class="col-num">Ср. время</th>
                    <th class="col-num">Заявки<br>курсы</th>
                    <th class="col-num">CR<br>визит→заявка</th>
                    <th class="col-num">Заказы</th>
                    <th class="col-num">CR<br>визит→заказ</th>
                    <th class="col-num">Оплачено</th>
                    <th class="col-num">CR<br>заказ→оплата</th>
                    <th class="col-num">CR<br>визит→оплата</th>
                    <th class="col-num">Выручка</th>
                    <th class="col-num">Ср. чек</th>
                </tr>
            </thead>
            <tbody>
                <!-- Строка "Итого" -->
                <tr class="utm-row-totals">
                    <td class="col-label"><strong>Итого и средние</strong></td>
                    <td class="col-num"><strong><?php echo number_format($totals['visits'], 0, ',', ' '); ?></strong></td>
                    <td class="col-num"><?php echo $totals['avg_duration_formatted']; ?></td>
                    <td class="col-num"><?php echo number_format($totals['course_applications'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $totals['conv_visit_to_app']; ?>%</td>
                    <td class="col-num"><?php echo number_format($totals['created_orders'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $totals['conv_visit_to_order']; ?>%</td>
                    <td class="col-num"><?php echo number_format($totals['paid_orders'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $totals['conv_order_to_paid']; ?>%</td>
                    <td class="col-num"><?php echo $totals['conv_visit_to_paid']; ?>%</td>
                    <td class="col-num"><strong><?php echo $totals['revenue_formatted']; ?> ₽</strong></td>
                    <td class="col-num"><?php echo $totals['avg_check_formatted']; ?> ₽</td>
                </tr>

                <!-- Строки UTM Source (свёрнуты) -->
                <?php foreach ($sourceData as $row): ?>
                <?php $isNoUtm = $row['label'] === '(без UTM)'; ?>
                <tr class="utm-row utm-row-source<?php if ($isNoUtm) echo ' utm-row-no-expand'; ?>"
                    data-level="source"
                    data-utm-source="<?php echo htmlspecialchars($row['label']); ?>"
                    data-expanded="false">
                    <td class="col-label">
                        <?php if ($isNoUtm): ?>
                            <span style="display:inline-block;width:16px;margin-right:4px;"></span>
                        <?php else: ?>
                            <span class="utm-toggle" title="Раскрыть">▶</span>
                        <?php endif; ?>
                        <span class="utm-label"><?php echo htmlspecialchars($row['label']); ?></span>
                    </td>
                    <td class="col-num"><?php echo number_format($row['visits'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $row['avg_duration_formatted']; ?></td>
                    <td class="col-num"><?php echo number_format($row['course_applications'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $row['conv_visit_to_app']; ?>%</td>
                    <td class="col-num"><?php echo number_format($row['created_orders'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $row['conv_visit_to_order']; ?>%</td>
                    <td class="col-num"><?php echo number_format($row['paid_orders'], 0, ',', ' '); ?></td>
                    <td class="col-num"><?php echo $row['conv_order_to_paid']; ?>%</td>
                    <td class="col-num"><?php echo $row['conv_visit_to_paid']; ?>%</td>
                    <td class="col-num"><?php echo $row['revenue_formatted']; ?> ₽</td>
                    <td class="col-num"><?php echo $row['avg_check_formatted']; ?> ₽</td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($sourceData)): ?>
                <tr>
                    <td colspan="12" style="text-align: center; padding: 40px; color: #888;">
                        Нет данных за выбранный период
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Передаём текущие фильтры в JS -->
<script>
    window.utmFilters = <?php echo json_encode($filters, JSON_UNESCAPED_UNICODE); ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

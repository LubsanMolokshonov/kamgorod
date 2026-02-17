<?php
/**
 * Admin Dashboard - Sales Analytics
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

$pageTitle = 'Дашборд продаж';

// --- Month filter ---
$filterYear = (int)($_GET['year'] ?? date('Y'));
$filterMonth = (int)($_GET['month'] ?? date('n'));

if ($filterMonth < 1 || $filterMonth > 12) $filterMonth = (int)date('n');
if ($filterYear < 2024 || $filterYear > 2030) $filterYear = (int)date('Y');

$startDate = sprintf('%04d-%02d-01 00:00:00', $filterYear, $filterMonth);
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));

$prevMonth = $filterMonth - 1;
$prevYear = $filterYear;
if ($prevMonth < 1) { $prevMonth = 12; $prevYear--; }

$nextMonth = $filterMonth + 1;
$nextYear = $filterYear;
if ($nextMonth > 12) { $nextMonth = 1; $nextYear++; }

$monthNames = [
    1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель',
    5 => 'Май', 6 => 'Июнь', 7 => 'Июль', 8 => 'Август',
    9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь'
];
$currentMonthName = $monthNames[$filterMonth] . ' ' . $filterYear;

// --- 1. Total revenue and items ---
$stmt = $db->prepare("
    SELECT COALESCE(SUM(oi.price), 0) as total_revenue,
           COUNT(oi.id) as total_items
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ?
      AND o.paid_at <= ?
");
$stmt->execute([$startDate, $endDate]);
$revenueSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// --- 2. Order count and average ---
$stmt = $db->prepare("
    SELECT COUNT(*) as total_orders,
           COALESCE(AVG(final_amount), 0) as avg_order_amount
    FROM orders
    WHERE payment_status = 'succeeded'
      AND paid_at >= ?
      AND paid_at <= ?
");
$stmt->execute([$startDate, $endDate]);
$orderSummary = $stmt->fetch(PDO::FETCH_ASSOC);

// --- 3. Breakdown by product type ---
$stmt = $db->prepare("
    SELECT
        CASE
            WHEN oi.registration_id IS NOT NULL THEN 'competitions'
            WHEN oi.certificate_id IS NOT NULL THEN 'publications'
            WHEN oi.webinar_certificate_id IS NOT NULL THEN 'webinars'
            ELSE 'unknown'
        END as product_type,
        COUNT(oi.id) as item_count,
        COALESCE(SUM(oi.price), 0) as revenue,
        SUM(CASE WHEN oi.is_free_promotion = 1 THEN 1 ELSE 0 END) as free_count
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ?
      AND o.paid_at <= ?
    GROUP BY product_type
    ORDER BY revenue DESC
");
$stmt->execute([$startDate, $endDate]);
$productBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 4. Daily breakdown ---
$stmt = $db->prepare("
    SELECT
        DATE(o.paid_at) as sale_date,
        COUNT(DISTINCT o.id) as order_count,
        COUNT(oi.id) as item_count,
        COALESCE(SUM(oi.price), 0) as daily_revenue,
        COALESCE(SUM(CASE WHEN oi.registration_id IS NOT NULL THEN oi.price ELSE 0 END), 0) as competitions_revenue,
        COALESCE(SUM(CASE WHEN oi.certificate_id IS NOT NULL THEN oi.price ELSE 0 END), 0) as publications_revenue,
        COALESCE(SUM(CASE WHEN oi.webinar_certificate_id IS NOT NULL THEN oi.price ELSE 0 END), 0) as webinars_revenue
    FROM order_items oi
    JOIN orders o ON oi.order_id = o.id
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ?
      AND o.paid_at <= ?
    GROUP BY DATE(o.paid_at)
    ORDER BY sale_date ASC
");
$stmt->execute([$startDate, $endDate]);
$dailyBreakdown = $stmt->fetchAll(PDO::FETCH_ASSOC);

// --- 5. Recent orders ---
$stmt = $db->prepare("
    SELECT o.id, o.order_number, o.final_amount, o.paid_at, o.promotion_applied,
           u.full_name, u.email,
           COUNT(oi.id) as item_count
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN order_items oi ON o.id = oi.order_id
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ?
      AND o.paid_at <= ?
    GROUP BY o.id
    ORDER BY o.paid_at DESC
    LIMIT 10
");
$stmt->execute([$startDate, $endDate]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<style>
.month-nav {
    display: flex;
    align-items: center;
    gap: 16px;
    margin-bottom: 32px;
    padding: 16px 24px;
    background: white;
    border-radius: 16px;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
}
.month-nav-current {
    font-size: 18px;
    font-weight: 700;
    color: #2C3E50;
    min-width: 180px;
    text-align: center;
}
</style>

<div class="page-header">
    <h1>Дашборд продаж</h1>
    <p>Аналитика продаж за <?php echo htmlspecialchars($currentMonthName); ?></p>
</div>

<!-- Month Navigation -->
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

<!-- Summary Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Выручка за период</div>
                <div class="stat-value"><?php echo number_format($revenueSummary['total_revenue'], 0, ',', ' '); ?> &#8381;</div>
            </div>
            <div class="stat-icon">💰</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Заказов</div>
                <div class="stat-value"><?php echo number_format($orderSummary['total_orders'], 0, ',', ' '); ?></div>
            </div>
            <div class="stat-icon">🛒</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Товаров продано</div>
                <div class="stat-value"><?php echo number_format($revenueSummary['total_items'], 0, ',', ' '); ?></div>
            </div>
            <div class="stat-icon">📦</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Средний чек</div>
                <div class="stat-value"><?php echo number_format($orderSummary['avg_order_amount'], 0, ',', ' '); ?> &#8381;</div>
            </div>
            <div class="stat-icon">📊</div>
        </div>
    </div>
</div>

<!-- Product Type Breakdown -->
<div class="content-card">
    <div class="card-header">
        <h2>Выручка по типам товаров</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($productBreakdown)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📊</div>
                <h3>Нет продаж</h3>
                <p>В выбранном периоде нет оплаченных заказов</p>
            </div>
        <?php else: ?>
            <?php
            $typeNames = [
                'competitions' => 'Конкурсы',
                'publications' => 'Свидетельства о публикации',
                'webinars' => 'Сертификаты вебинаров',
                'unknown' => 'Другое'
            ];
            $typeBadges = [
                'competitions' => 'badge-success',
                'publications' => 'badge-info',
                'webinars' => 'badge-purple',
                'unknown' => 'badge-warning'
            ];
            $totalRevenue = $revenueSummary['total_revenue'] ?: 1;
            ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Тип товара</th>
                        <th>Кол-во продаж</th>
                        <th>Бесплатных (акция)</th>
                        <th>Выручка</th>
                        <th>Доля</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productBreakdown as $type): ?>
                        <tr>
                            <td>
                                <span class="badge <?php echo $typeBadges[$type['product_type']] ?? 'badge-warning'; ?>">
                                    <?php echo $typeNames[$type['product_type']] ?? $type['product_type']; ?>
                                </span>
                            </td>
                            <td><?php echo $type['item_count']; ?></td>
                            <td><?php echo $type['free_count']; ?></td>
                            <td><?php echo number_format($type['revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo number_format(($type['revenue'] / $totalRevenue) * 100, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: #f9fafb;">
                        <td>Итого</td>
                        <td><?php echo $revenueSummary['total_items']; ?></td>
                        <td></td>
                        <td><?php echo number_format($revenueSummary['total_revenue'], 0, ',', ' '); ?> &#8381;</td>
                        <td>100%</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Daily Breakdown -->
<div class="content-card">
    <div class="card-header">
        <h2>Продажи по дням</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($dailyBreakdown)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📅</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет продаж</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Заказов</th>
                        <th>Товаров</th>
                        <th>Конкурсы</th>
                        <th>Публикации</th>
                        <th>Вебинары</th>
                        <th>Итого</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyBreakdown as $day): ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($day['sale_date'])); ?></td>
                            <td><?php echo $day['order_count']; ?></td>
                            <td><?php echo $day['item_count']; ?></td>
                            <td><?php echo number_format($day['competitions_revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo number_format($day['publications_revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo number_format($day['webinars_revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><strong><?php echo number_format($day['daily_revenue'], 0, ',', ' '); ?> &#8381;</strong></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: #f9fafb;">
                        <td>Итого</td>
                        <td><?php echo array_sum(array_column($dailyBreakdown, 'order_count')); ?></td>
                        <td><?php echo array_sum(array_column($dailyBreakdown, 'item_count')); ?></td>
                        <td><?php echo number_format(array_sum(array_column($dailyBreakdown, 'competitions_revenue')), 0, ',', ' '); ?> &#8381;</td>
                        <td><?php echo number_format(array_sum(array_column($dailyBreakdown, 'publications_revenue')), 0, ',', ' '); ?> &#8381;</td>
                        <td><?php echo number_format(array_sum(array_column($dailyBreakdown, 'webinars_revenue')), 0, ',', ' '); ?> &#8381;</td>
                        <td><?php echo number_format(array_sum(array_column($dailyBreakdown, 'daily_revenue')), 0, ',', ' '); ?> &#8381;</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Recent Orders -->
<div class="content-card">
    <div class="card-header">
        <h2>Последние заказы</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentOrders)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🛒</div>
                <h3>Нет заказов</h3>
                <p>В выбранном периоде нет оплаченных заказов</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Номер заказа</th>
                        <th>Покупатель</th>
                        <th>Email</th>
                        <th>Товаров</th>
                        <th>Сумма</th>
                        <th>Акция</th>
                        <th>Дата оплаты</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentOrders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($order['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['email']); ?></td>
                            <td><?php echo $order['item_count']; ?></td>
                            <td><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> &#8381;</td>
                            <td>
                                <?php if ($order['promotion_applied']): ?>
                                    <span class="badge badge-warning">2+1</span>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($order['paid_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

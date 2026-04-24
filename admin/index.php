<?php
/**
 * Admin Dashboard - Sales Analytics
 * Разделение: Педпортал (конкурсы, олимпиады, вебинары, публикации) и Курсы
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

// === ПЕДПОРТАЛ: Заказы (уникальные заказы педпортала за период) ===
$stmt = $db->prepare("
    SELECT COUNT(DISTINCT o.id) as total_orders
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.created_at >= ? AND o.created_at <= ?
      AND (oi.registration_id IS NOT NULL
           OR oi.certificate_id IS NOT NULL
           OR oi.webinar_certificate_id IS NOT NULL
           OR oi.olympiad_registration_id IS NOT NULL)
");
$stmt->execute([$startDate, $endDate]);
$pedportalOrders = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total_orders'];

// === ПЕДПОРТАЛ: Оплаты и выручка ===
$stmt = $db->prepare("
    SELECT COUNT(*) as paid_count,
           COALESCE(SUM(o.final_amount), 0) as revenue
    FROM orders o
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ? AND o.paid_at <= ?
      AND EXISTS (
          SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
          AND (oi.registration_id IS NOT NULL
               OR oi.certificate_id IS NOT NULL
               OR oi.webinar_certificate_id IS NOT NULL
               OR oi.olympiad_registration_id IS NOT NULL)
      )
");
$stmt->execute([$startDate, $endDate]);
$pedportalPaid = $stmt->fetch(PDO::FETCH_ASSOC);
$pedportalPaidCount = (int)$pedportalPaid['paid_count'];
$pedportalRevenue = (float)$pedportalPaid['revenue'];
$pedportalConversion = $pedportalOrders > 0 ? round($pedportalPaidCount / $pedportalOrders * 100, 1) : 0;
$pedportalAvgCheck = $pedportalPaidCount > 0 ? round($pedportalRevenue / $pedportalPaidCount) : 0;

// === КУРСЫ: Заявки (регистрации на курс + заявки на консультацию) ===
$stmt = $db->prepare("
    SELECT COUNT(*) as total_applications FROM (
        SELECT created_at FROM course_enrollments
        WHERE created_at >= ? AND created_at <= ?
        UNION ALL
        SELECT created_at FROM course_consultations
        WHERE created_at >= ? AND created_at <= ?
    ) t
");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$coursesApps = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total_applications'];

// === КУРСЫ: Оплаты и выручка ===
$stmt = $db->prepare("
    SELECT COUNT(*) as paid_count,
           COALESCE(SUM(o.final_amount), 0) as revenue
    FROM orders o
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ? AND o.paid_at <= ?
      AND EXISTS (
          SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
          AND oi.course_enrollment_id IS NOT NULL
      )
");
$stmt->execute([$startDate, $endDate]);
$coursesPaid = $stmt->fetch(PDO::FETCH_ASSOC);
$coursesPaidCount = (int)$coursesPaid['paid_count'];
$coursesRevenue = (float)$coursesPaid['revenue'];
$coursesConversion = $coursesApps > 0 ? round($coursesPaidCount / $coursesApps * 100, 1) : 0;
$coursesAvgCheck = $coursesPaidCount > 0 ? round($coursesRevenue / $coursesPaidCount) : 0;

// === ОБЩИЕ: Итого ===
$totalOrders = $pedportalOrders + $coursesApps;
$totalPaid = $pedportalPaidCount + $coursesPaidCount;
$totalRevenue = $pedportalRevenue + $coursesRevenue;
$totalConversion = $totalOrders > 0 ? round($totalPaid / $totalOrders * 100, 1) : 0;
$totalAvgCheck = $totalPaid > 0 ? round($totalRevenue / $totalPaid) : 0;

// === Breakdown по типам товаров ===
$stmt = $db->prepare("
    SELECT
        CASE
            WHEN oi.registration_id IS NOT NULL THEN 'competitions'
            WHEN oi.certificate_id IS NOT NULL THEN 'publications'
            WHEN oi.webinar_certificate_id IS NOT NULL THEN 'webinars'
            WHEN oi.olympiad_registration_id IS NOT NULL THEN 'olympiads'
            WHEN oi.course_enrollment_id IS NOT NULL THEN 'courses'
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

// === Дневной breakdown: Педпортал (заказы + оплаты по дням) ===
// Заказы по дням (уникальные заказы педпортала, любой статус)
$stmt = $db->prepare("
    SELECT DATE(o.created_at) as sale_date,
           COUNT(DISTINCT o.id) as orders_count
    FROM orders o
    JOIN order_items oi ON oi.order_id = o.id
    WHERE o.created_at >= ? AND o.created_at <= ?
      AND (oi.registration_id IS NOT NULL
           OR oi.certificate_id IS NOT NULL
           OR oi.webinar_certificate_id IS NOT NULL
           OR oi.olympiad_registration_id IS NOT NULL)
    GROUP BY DATE(o.created_at)
");
$stmt->execute([$startDate, $endDate]);
$pedportalOrdersByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pedportalOrdersByDay[$row['sale_date']] = (int)$row['orders_count'];
}

// Оплаты + выручка по дням (succeeded)
$stmt = $db->prepare("
    SELECT DATE(o.paid_at) as sale_date,
           COUNT(*) as paid_count,
           COALESCE(SUM(o.final_amount), 0) as revenue
    FROM orders o
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ? AND o.paid_at <= ?
      AND EXISTS (
          SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
          AND (oi.registration_id IS NOT NULL
               OR oi.certificate_id IS NOT NULL
               OR oi.webinar_certificate_id IS NOT NULL
               OR oi.olympiad_registration_id IS NOT NULL)
      )
    GROUP BY DATE(o.paid_at)
");
$stmt->execute([$startDate, $endDate]);
$pedportalPaidByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $pedportalPaidByDay[$row['sale_date']] = $row;
}

// Собираем все даты педпортала
$allPedportalDates = array_unique(array_merge(array_keys($pedportalOrdersByDay), array_keys($pedportalPaidByDay)));
sort($allPedportalDates);
$dailyPedportal = [];
foreach ($allPedportalDates as $date) {
    $orders = $pedportalOrdersByDay[$date] ?? 0;
    $paid = (int)($pedportalPaidByDay[$date]['paid_count'] ?? 0);
    $revenue = (float)($pedportalPaidByDay[$date]['revenue'] ?? 0);
    $dailyPedportal[] = [
        'sale_date' => $date,
        'orders_count' => $orders,
        'paid_count' => $paid,
        'revenue' => $revenue,
        'conversion' => $orders > 0 ? round($paid / $orders * 100, 1) : 0,
        'avg_check' => $paid > 0 ? round($revenue / $paid) : 0,
    ];
}

// === Дневной breakdown: Курсы (заявки + оплаты по дням) ===
// Заявки по дням (регистрации на курс + заявки на консультацию)
$stmt = $db->prepare("
    SELECT DATE(created_at) as sale_date,
           COUNT(*) as apps_count
    FROM (
        SELECT created_at FROM course_enrollments
        WHERE created_at >= ? AND created_at <= ?
        UNION ALL
        SELECT created_at FROM course_consultations
        WHERE created_at >= ? AND created_at <= ?
    ) t
    GROUP BY DATE(created_at)
");
$stmt->execute([$startDate, $endDate, $startDate, $endDate]);
$coursesAppsByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $coursesAppsByDay[$row['sale_date']] = (int)$row['apps_count'];
}

// Оплаты + выручка по дням
$stmt = $db->prepare("
    SELECT DATE(o.paid_at) as sale_date,
           COUNT(*) as paid_count,
           COALESCE(SUM(o.final_amount), 0) as revenue
    FROM orders o
    WHERE o.payment_status = 'succeeded'
      AND o.paid_at >= ? AND o.paid_at <= ?
      AND EXISTS (
          SELECT 1 FROM order_items oi WHERE oi.order_id = o.id
          AND oi.course_enrollment_id IS NOT NULL
      )
    GROUP BY DATE(o.paid_at)
");
$stmt->execute([$startDate, $endDate]);
$coursesPaidByDay = [];
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $coursesPaidByDay[$row['sale_date']] = $row;
}

// Собираем все даты курсов
$allCoursesDates = array_unique(array_merge(array_keys($coursesAppsByDay), array_keys($coursesPaidByDay)));
sort($allCoursesDates);
$dailyCourses = [];
foreach ($allCoursesDates as $date) {
    $apps = $coursesAppsByDay[$date] ?? 0;
    $paid = (int)($coursesPaidByDay[$date]['paid_count'] ?? 0);
    $revenue = (float)($coursesPaidByDay[$date]['revenue'] ?? 0);
    $dailyCourses[] = [
        'sale_date' => $date,
        'apps_count' => $apps,
        'paid_count' => $paid,
        'revenue' => $revenue,
        'conversion' => $apps > 0 ? round($paid / $apps * 100, 1) : 0,
        'avg_check' => $paid > 0 ? round($revenue / $paid) : 0,
    ];
}

// === Последние заказы ===
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
.kpi-sections {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    margin-bottom: 32px;
}
.kpi-section-header {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 20px;
    padding-bottom: 12px;
    border-bottom: 2px solid #e5e7eb;
}
.kpi-section-header h2 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}
.kpi-section-header .section-tag {
    font-size: 12px;
    padding: 2px 10px;
    border-radius: 20px;
    font-weight: 600;
}
.tag-pedportal {
    background: #ede9fe;
    color: #6d28d9;
}
.tag-courses {
    background: #dbeafe;
    color: #1d4ed8;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 16px;
}
.kpi-grid .stat-card:nth-child(4),
.kpi-grid .stat-card:nth-child(5) {
    /* 4th and 5th cards span to fill the row */
}
.kpi-value-highlight {
    font-size: 28px;
    font-weight: 800;
    color: #1e293b;
    line-height: 1.2;
}
.kpi-label {
    font-size: 13px;
    color: #64748b;
    margin-top: 4px;
    font-weight: 500;
}
.totals-row {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 16px;
    margin-bottom: 32px;
}
@media (max-width: 1024px) {
    .kpi-sections {
        grid-template-columns: 1fr;
    }
    .totals-row {
        grid-template-columns: repeat(3, 1fr);
    }
}
@media (max-width: 768px) {
    .kpi-grid {
        grid-template-columns: 1fr 1fr;
    }
    .totals-row {
        grid-template-columns: 1fr 1fr;
    }
}
</style>

<div class="page-header">
    <h1>Дашборд продаж</h1>
    <p>Аналитика за <?php echo htmlspecialchars($currentMonthName); ?></p>
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

<!-- KPI: Итого -->
<div class="content-card" style="margin-bottom: 24px;">
    <div class="kpi-section-header">
        <h2>Итого</h2>
    </div>
    <div class="totals-row">
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totalOrders, 0, ',', ' '); ?></div>
            <div class="kpi-label">Заказы / Заявки</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totalPaid, 0, ',', ' '); ?></div>
            <div class="kpi-label">Оплаты</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totalRevenue, 0, ',', ' '); ?> &#8381;</div>
            <div class="kpi-label">Выручка</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo $totalConversion; ?>%</div>
            <div class="kpi-label">Конверсия</div>
        </div>
        <div class="stat-card">
            <div class="kpi-value-highlight"><?php echo number_format($totalAvgCheck, 0, ',', ' '); ?> &#8381;</div>
            <div class="kpi-label">Средний чек</div>
        </div>
    </div>
</div>

<!-- KPI: Педпортал и Курсы -->
<div class="kpi-sections">
    <!-- Педпортал -->
    <div class="content-card">
        <div class="kpi-section-header">
            <h2>Педпортал</h2>
            <span class="section-tag tag-pedportal">Конкурсы, олимпиады, вебинары, публикации</span>
        </div>
        <div class="kpi-grid">
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($pedportalOrders, 0, ',', ' '); ?></div>
                <div class="kpi-label">Заказы</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($pedportalPaidCount, 0, ',', ' '); ?></div>
                <div class="kpi-label">Оплаты</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($pedportalRevenue, 0, ',', ' '); ?> &#8381;</div>
                <div class="kpi-label">Выручка</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo $pedportalConversion; ?>%</div>
                <div class="kpi-label">Конверсия</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($pedportalAvgCheck, 0, ',', ' '); ?> &#8381;</div>
                <div class="kpi-label">Средний чек</div>
            </div>
        </div>
    </div>

    <!-- Курсы -->
    <div class="content-card">
        <div class="kpi-section-header">
            <h2>Курсы</h2>
            <span class="section-tag tag-courses">Повышение квалификации и переподготовка</span>
        </div>
        <div class="kpi-grid">
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($coursesApps, 0, ',', ' '); ?></div>
                <div class="kpi-label">Заявки</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($coursesPaidCount, 0, ',', ' '); ?></div>
                <div class="kpi-label">Оплаты</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($coursesRevenue, 0, ',', ' '); ?> &#8381;</div>
                <div class="kpi-label">Выручка</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo $coursesConversion; ?>%</div>
                <div class="kpi-label">Конверсия</div>
            </div>
            <div class="stat-card">
                <div class="kpi-value-highlight"><?php echo number_format($coursesAvgCheck, 0, ',', ' '); ?> &#8381;</div>
                <div class="kpi-label">Средний чек</div>
            </div>
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
                'olympiads' => 'Олимпиады',
                'courses' => 'Курсы',
                'unknown' => 'Другое'
            ];
            $typeBadges = [
                'competitions' => 'badge-success',
                'publications' => 'badge-info',
                'webinars' => 'badge-purple',
                'olympiads' => 'badge-warning',
                'courses' => 'badge-primary',
                'unknown' => 'badge-secondary'
            ];
            $totalRevenueAll = $totalRevenue ?: 1;
            $totalItems = array_sum(array_column($productBreakdown, 'item_count'));
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
                                <span class="badge <?php echo $typeBadges[$type['product_type']] ?? 'badge-secondary'; ?>">
                                    <?php echo $typeNames[$type['product_type']] ?? $type['product_type']; ?>
                                </span>
                            </td>
                            <td><?php echo $type['item_count']; ?></td>
                            <td><?php echo $type['free_count']; ?></td>
                            <td><?php echo number_format($type['revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo number_format(($type['revenue'] / $totalRevenueAll) * 100, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: #f9fafb;">
                        <td>Итого</td>
                        <td><?php echo $totalItems; ?></td>
                        <td></td>
                        <td><?php echo number_format($totalRevenue, 0, ',', ' '); ?> &#8381;</td>
                        <td>100%</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Daily Breakdown: Педпортал -->
<div class="content-card">
    <div class="card-header">
        <h2>Педпортал — по дням</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($dailyPedportal)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📅</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет данных по педпорталу</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Заказы</th>
                        <th>Оплаты</th>
                        <th>Выручка</th>
                        <th>Конверсия</th>
                        <th>Средний чек</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyPedportal as $day): ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($day['sale_date'])); ?></td>
                            <td><?php echo $day['orders_count']; ?></td>
                            <td><?php echo $day['paid_count']; ?></td>
                            <td><?php echo number_format($day['revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo $day['conversion']; ?>%</td>
                            <td><?php echo number_format($day['avg_check'], 0, ',', ' '); ?> &#8381;</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: #f9fafb;">
                        <td>Итого</td>
                        <td><?php echo array_sum(array_column($dailyPedportal, 'orders_count')); ?></td>
                        <td><?php echo array_sum(array_column($dailyPedportal, 'paid_count')); ?></td>
                        <td><?php echo number_format(array_sum(array_column($dailyPedportal, 'revenue')), 0, ',', ' '); ?> &#8381;</td>
                        <td><?php echo $pedportalConversion; ?>%</td>
                        <td><?php echo number_format($pedportalAvgCheck, 0, ',', ' '); ?> &#8381;</td>
                    </tr>
                </tfoot>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Daily Breakdown: Курсы -->
<div class="content-card">
    <div class="card-header">
        <h2>Курсы — по дням</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($dailyCourses)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📅</div>
                <h3>Нет данных</h3>
                <p>В выбранном периоде нет данных по курсам</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Дата</th>
                        <th>Заявки</th>
                        <th>Оплаты</th>
                        <th>Выручка</th>
                        <th>Конверсия</th>
                        <th>Средний чек</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($dailyCourses as $day): ?>
                        <tr>
                            <td><?php echo date('d.m.Y', strtotime($day['sale_date'])); ?></td>
                            <td><?php echo $day['apps_count']; ?></td>
                            <td><?php echo $day['paid_count']; ?></td>
                            <td><?php echo number_format($day['revenue'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo $day['conversion']; ?>%</td>
                            <td><?php echo number_format($day['avg_check'], 0, ',', ' '); ?> &#8381;</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="font-weight: 700; background: #f9fafb;">
                        <td>Итого</td>
                        <td><?php echo array_sum(array_column($dailyCourses, 'apps_count')); ?></td>
                        <td><?php echo array_sum(array_column($dailyCourses, 'paid_count')); ?></td>
                        <td><?php echo number_format(array_sum(array_column($dailyCourses, 'revenue')), 0, ',', ' '); ?> &#8381;</td>
                        <td><?php echo $coursesConversion; ?>%</td>
                        <td><?php echo number_format($coursesAvgCheck, 0, ',', ' '); ?> &#8381;</td>
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

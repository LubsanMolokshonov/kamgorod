<?php
/**
 * Orders Management - List
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Заказы';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Status filter
$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['pending', 'processing', 'succeeded', 'failed', 'refunded'];
if ($statusFilter && !in_array($statusFilter, $validStatuses)) {
    $statusFilter = '';
}

// Build query
$whereClause = '';
$params = [];
if ($statusFilter) {
    $whereClause = 'WHERE o.payment_status = ?';
    $params[] = $statusFilter;
}

// Total count
$countSql = "SELECT COUNT(*) as total FROM orders o $whereClause";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$totalOrders = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = max(1, ceil($totalOrders / $perPage));

// Get orders
$stmt = $db->prepare("
    SELECT o.*, u.full_name, u.email,
           COUNT(oi.id) as item_count
    FROM orders o
    JOIN users u ON o.user_id = u.id
    LEFT JOIN order_items oi ON o.id = oi.order_id
    $whereClause
    GROUP BY o.id
    ORDER BY o.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusNames = [
    'pending' => 'Ожидание',
    'processing' => 'Обработка',
    'succeeded' => 'Оплачен',
    'failed' => 'Неудача',
    'refunded' => 'Возврат'
];
$statusBadges = [
    'pending' => 'badge-warning',
    'processing' => 'badge-info',
    'succeeded' => 'badge-success',
    'failed' => 'badge-danger',
    'refunded' => 'badge-purple'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Заказы</h1>
    <p>Всего: <?php echo number_format($totalOrders, 0, ',', ' '); ?></p>
</div>

<!-- Status filter -->
<div style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="?" class="btn <?php echo !$statusFilter ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">Все</a>
    <?php foreach ($statusNames as $key => $name): ?>
        <a href="?status=<?php echo $key; ?>" class="btn <?php echo $statusFilter === $key ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
            <?php echo $name; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($orders)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <h3>Нет заказов</h3>
                <p>Заказы появятся здесь после оплаты</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Номер</th>
                        <th>Покупатель</th>
                        <th>Email</th>
                        <th>Товаров</th>
                        <th>Сумма</th>
                        <th>Скидка</th>
                        <th>Итого</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($order['order_number']); ?></td>
                            <td><?php echo htmlspecialchars($order['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($order['email']); ?></td>
                            <td><?php echo $order['item_count']; ?></td>
                            <td><?php echo number_format($order['total_amount'], 0, ',', ' '); ?> &#8381;</td>
                            <td>
                                <?php if ($order['discount_amount'] > 0): ?>
                                    -<?php echo number_format($order['discount_amount'], 0, ',', ' '); ?> &#8381;
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo number_format($order['final_amount'], 0, ',', ' '); ?> &#8381;</strong></td>
                            <td>
                                <span class="badge <?php echo $statusBadges[$order['payment_status']] ?? 'badge-warning'; ?>">
                                    <?php echo $statusNames[$order['payment_status']] ?? $order['payment_status']; ?>
                                </span>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($order['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div style="padding: 16px 24px; display: flex; gap: 8px; justify-content: center;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?>" class="btn btn-secondary btn-sm">&larr;</a>
                    <?php endif; ?>
                    <span style="padding: 6px 14px; font-size: 13px;">Стр. <?php echo $page; ?> из <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $statusFilter ? '&status=' . $statusFilter : ''; ?>" class="btn btn-secondary btn-sm">&rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
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

// A/B-тест: фильтр по варианту модели оплаты.
$variantFilter = $_GET['variant'] ?? '';
if ($variantFilter && !in_array($variantFilter, ['A', 'B'], true)) {
    $variantFilter = '';
}

// Build query
$conditions = [];
$params = [];
if ($statusFilter) {
    $conditions[] = 'o.payment_status = ?';
    $params[] = $statusFilter;
}
if ($variantFilter) {
    $conditions[] = 'o.pricing_variant = ?';
    $params[] = $variantFilter;
}
$whereClause = $conditions ? ('WHERE ' . implode(' AND ', $conditions)) : '';

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
<?php $vq = $variantFilter ? '&variant=' . $variantFilter : ''; ?>
<div style="margin-bottom: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="?<?php echo $variantFilter ? 'variant=' . $variantFilter : ''; ?>" class="btn <?php echo !$statusFilter ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">Все</a>
    <?php foreach ($statusNames as $key => $name): ?>
        <a href="?status=<?php echo $key . $vq; ?>" class="btn <?php echo $statusFilter === $key ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
            <?php echo $name; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- A/B variant filter -->
<?php $sq = $statusFilter ? '&status=' . $statusFilter : ''; ?>
<div style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap; align-items:center;">
    <span style="color:#888;font-size:13px;">A/B модель:</span>
    <a href="?<?php echo $statusFilter ? 'status=' . $statusFilter : ''; ?>" class="btn <?php echo !$variantFilter ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">Все</a>
    <a href="?variant=A<?php echo $sq; ?>" class="btn <?php echo $variantFilter === 'A' ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">A · поштучно</a>
    <a href="?variant=B<?php echo $sq; ?>" class="btn <?php echo $variantFilter === 'B' ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">B · подписка</a>
    <a href="/admin/ab-test/" class="btn btn-secondary btn-sm" style="margin-left:auto;">📊 Дашборд A/B</a>
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
                        <th>A/B</th>
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
                                <?php $pv = $order['pricing_variant'] ?? null; ?>
                                <?php if ($pv === 'A'): ?>
                                    <span class="badge badge-info">A</span>
                                <?php elseif ($pv === 'B'): ?>
                                    <span class="badge badge-purple">B</span>
                                <?php else: ?>
                                    &mdash;
                                <?php endif; ?>
                            </td>
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
                        <a href="?page=<?php echo $page - 1; ?><?php echo ($statusFilter ? '&status=' . $statusFilter : '') . ($variantFilter ? '&variant=' . $variantFilter : ''); ?>" class="btn btn-secondary btn-sm">&larr;</a>
                    <?php endif; ?>
                    <span style="padding: 6px 14px; font-size: 13px;">Стр. <?php echo $page; ?> из <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo ($statusFilter ? '&status=' . $statusFilter : '') . ($variantFilter ? '&variant=' . $variantFilter : ''); ?>" class="btn btn-secondary btn-sm">&rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

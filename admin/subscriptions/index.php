<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Подписки — список user_subscriptions (фильтр по статусу, пагинация).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Подписки';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['active', 'pending', 'cancelled', 'expired'];
if ($statusFilter && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$where = '';
$params = [];
if ($statusFilter) {
    $where = 'WHERE us.status = ?';
    $params[] = $statusFilter;
}

$countSql = "SELECT COUNT(*) AS total FROM user_subscriptions us $where";
$stmt = $db->prepare($countSql);
$stmt->execute($params);
$total = (int)$stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = max(1, ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT us.*, u.email, u.full_name, p.name AS plan_name, p.slug AS plan_slug
    FROM user_subscriptions us
    JOIN users u ON us.user_id = u.id
    JOIN subscription_plans p ON us.plan_id = p.id
    $where
    ORDER BY us.id DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$subs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Сводка
$summary = $db->query("
    SELECT status, COUNT(*) AS cnt FROM user_subscriptions GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$statusNames = ['active' => 'Активна', 'pending' => 'Ожидание', 'cancelled' => 'Отменена', 'expired' => 'Истекла'];
$statusBadges = ['active' => 'badge-success', 'pending' => 'badge-warning', 'cancelled' => 'badge-purple', 'expired' => 'badge-danger'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Подписки</h1>
    <p>Всего: <?php echo number_format($total, 0, ',', ' '); ?> · Активных: <?php echo (int)($summary['active'] ?? 0); ?></p>
</div>

<div style="margin-bottom: 24px; display: flex; gap: 8px; flex-wrap: wrap; align-items:center;">
    <a href="?" class="btn <?php echo !$statusFilter ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">Все</a>
    <?php foreach ($statusNames as $key => $name): ?>
        <a href="?status=<?php echo $key; ?>" class="btn <?php echo $statusFilter === $key ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
            <?php echo $name; ?> (<?php echo (int)($summary[$key] ?? 0); ?>)
        </a>
    <?php endforeach; ?>
    <a href="/admin/subscriptions/plans.php" class="btn btn-secondary btn-sm" style="margin-left:auto;">⚙️ Тарифы</a>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($subs)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">⭐</div>
                <h3>Нет подписок</h3>
                <p>Подписки появятся здесь после первой оплаты тарифа</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Пользователь</th>
                        <th>Тариф</th>
                        <th>Период</th>
                        <th>Статус</th>
                        <th>Начало</th>
                        <th>Окончание</th>
                        <th>Автопродление</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($subs as $s): ?>
                        <tr>
                            <td><?php echo (int)$s['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($s['full_name'] ?: '—'); ?><br>
                                <span style="color:#888;font-size:12px;"><?php echo htmlspecialchars($s['email']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($s['plan_name']); ?></td>
                            <td><?php echo $s['period'] === 'yearly' ? 'Год' : 'Месяц'; ?></td>
                            <td><span class="badge <?php echo $statusBadges[$s['status']] ?? 'badge-warning'; ?>"><?php echo $statusNames[$s['status']] ?? $s['status']; ?></span></td>
                            <td><?php echo $s['started_at'] ? date('d.m.Y', strtotime($s['started_at'])) : '—'; ?></td>
                            <td><?php echo $s['expires_at'] ? date('d.m.Y', strtotime($s['expires_at'])) : '—'; ?></td>
                            <td><?php echo $s['auto_renew'] ? '✓' : '—'; ?></td>
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

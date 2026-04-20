<?php
/**
 * Админ: список алертов от ИИ-консультанта
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Алерты';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$statusFilter = $_GET['status'] ?? '';
$validStatuses = ['new', 'in_progress', 'resolved', 'closed'];
if ($statusFilter && !in_array($statusFilter, $validStatuses, true)) {
    $statusFilter = '';
}

$search = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];
if ($statusFilter) {
    $where[] = 'status = ?';
    $params[] = $statusFilter;
}
if ($search !== '') {
    $where[] = '(user_email LIKE ? OR user_name LIKE ? OR description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

$stmt = $db->prepare("SELECT COUNT(*) FROM support_alerts $whereClause");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$stmt = $db->prepare("
    SELECT id, user_name, user_email, user_phone, page_url, description,
           ai_summary, ai_category, status, created_at
    FROM support_alerts
    $whereClause
    ORDER BY CASE status WHEN 'new' THEN 0 WHEN 'in_progress' THEN 1 ELSE 2 END, created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);

$statusNames = [
    'new' => 'Новый',
    'in_progress' => 'В работе',
    'resolved' => 'Решён',
    'closed' => 'Закрыт',
];
$statusBadges = [
    'new' => 'badge-warning',
    'in_progress' => 'badge-info',
    'resolved' => 'badge-success',
    'closed' => 'badge-purple',
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Алерты от пользователей</h1>
    <p>Всего: <?php echo number_format($total, 0, ',', ' '); ?></p>
</div>

<div style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap;">
    <a href="?" class="btn <?php echo !$statusFilter ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">Все</a>
    <?php foreach ($statusNames as $key => $name): ?>
        <a href="?status=<?php echo $key; ?>" class="btn <?php echo $statusFilter === $key ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
            <?php echo $name; ?>
        </a>
    <?php endforeach; ?>
</div>

<form method="get" style="margin-bottom: 16px;">
    <?php if ($statusFilter): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter); ?>"><?php endif; ?>
    <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Поиск по email, имени, описанию" style="padding: 8px 12px; border: 1px solid #E5E7EB; border-radius: 6px; width: 320px;">
    <button type="submit" class="btn btn-primary btn-sm">Найти</button>
</form>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($alerts)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🔔</div>
                <h3>Нет алертов</h3>
                <p>Заявки от пользователей появятся здесь</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Дата</th>
                        <th>Пользователь</th>
                        <th>Страница</th>
                        <th>Описание</th>
                        <th>Категория</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($alerts as $a): ?>
                        <tr>
                            <td>#<?php echo $a['id']; ?></td>
                            <td><?php echo date('d.m.Y H:i', strtotime($a['created_at'])); ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($a['user_name']); ?></strong><br>
                                <span style="font-size: 12px; color: #6B7280;">
                                    <?php echo htmlspecialchars($a['user_email']); ?>
                                    <?php if ($a['user_phone']): ?><br><?php echo htmlspecialchars($a['user_phone']); ?><?php endif; ?>
                                </span>
                            </td>
                            <td style="font-size: 12px; color: #6B7280; max-width: 160px; word-break: break-all;">
                                <?php echo htmlspecialchars($a['page_url'] ?? '—'); ?>
                            </td>
                            <td style="max-width: 280px;">
                                <?php if ($a['ai_summary']): ?>
                                    <em style="color: #7F56D9; font-size: 12px;"><?php echo htmlspecialchars($a['ai_summary']); ?></em><br>
                                <?php endif; ?>
                                <span style="font-size: 13px;"><?php echo htmlspecialchars(mb_substr($a['description'], 0, 100) . (mb_strlen($a['description']) > 100 ? '…' : '')); ?></span>
                            </td>
                            <td>
                                <?php if ($a['ai_category']): ?>
                                    <span class="badge badge-info"><?php echo htmlspecialchars($a['ai_category']); ?></span>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $statusBadges[$a['status']] ?? 'badge-warning'; ?>">
                                    <?php echo $statusNames[$a['status']] ?? $a['status']; ?>
                                </span>
                            </td>
                            <td>
                                <a href="/admin/alerts/view.php?id=<?php echo $a['id']; ?>" class="btn btn-primary btn-sm">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <?php
                $qsBase = [];
                if ($statusFilter) $qsBase['status'] = $statusFilter;
                if ($search !== '') $qsBase['q'] = $search;
                $buildQs = function ($p) use ($qsBase) {
                    $qsBase['page'] = $p;
                    return http_build_query($qsBase);
                };
                ?>
                <div style="padding: 16px 24px; display: flex; gap: 8px; justify-content: center;">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo $buildQs($page - 1); ?>" class="btn btn-secondary btn-sm">&larr;</a>
                    <?php endif; ?>
                    <span style="padding: 6px 14px; font-size: 13px;">Стр. <?php echo $page; ?> из <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo $buildQs($page + 1); ?>" class="btn btn-secondary btn-sm">&rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

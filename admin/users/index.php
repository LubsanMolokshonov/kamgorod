<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Users Management - List
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Пользователи';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Search
$search = trim($_GET['q'] ?? '');

$whereClause = '';
$params = [];
if ($search) {
    $whereClause = 'WHERE u.full_name LIKE ? OR u.email LIKE ?';
    $params = ["%$search%", "%$search%"];
}

// Total count
$stmt = $db->prepare("SELECT COUNT(*) as total FROM users u $whereClause");
$stmt->execute($params);
$totalUsers = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
$totalPages = max(1, ceil($totalUsers / $perPage));

// Get users with stats
$stmt = $db->prepare("
    SELECT u.*,
           COUNT(DISTINCT r.id) as registration_count,
           SUM(CASE WHEN r.status IN ('paid', 'diploma_ready') THEN 1 ELSE 0 END) as paid_count
    FROM users u
    LEFT JOIN registrations r ON u.id = r.user_id
    $whereClause
    GROUP BY u.id
    ORDER BY u.created_at DESC
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Пользователи</h1>
    <p>Всего: <?php echo number_format($totalUsers, 0, ',', ' '); ?></p>
</div>

<!-- Search -->
<div style="margin-bottom: 24px;">
    <form method="GET" style="display: flex; gap: 8px; max-width: 400px;">
        <input type="text" name="q" class="form-control" placeholder="Поиск по имени или email..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-primary btn-sm">Найти</button>
        <?php if ($search): ?>
            <a href="?" class="btn btn-secondary btn-sm">Сброс</a>
        <?php endif; ?>
    </form>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($users)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">👥</div>
                <h3>Нет пользователей</h3>
                <p><?php echo $search ? 'Ничего не найдено' : 'Пользователи появятся здесь после регистрации'; ?></p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Имя</th>
                        <th>Email</th>
                        <th>Телефон</th>
                        <th>Город</th>
                        <th>Регистраций</th>
                        <th>Оплачено</th>
                        <th>Дата регистрации</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>#<?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td><?php echo htmlspecialchars($user['phone'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars($user['city'] ?? ''); ?></td>
                            <td><?php echo $user['registration_count']; ?></td>
                            <td><?php echo $user['paid_count']; ?></td>
                            <td><?php echo date('d.m.Y', strtotime($user['created_at'])); ?></td>
                            <td><a href="/admin/users/edit.php?id=<?php echo $user['id']; ?>" class="btn btn-secondary btn-sm">Редактировать</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <div style="padding: 16px 24px; display: flex; gap: 8px; justify-content: center;">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="btn btn-secondary btn-sm">&larr;</a>
                    <?php endif; ?>
                    <span style="padding: 6px 14px; font-size: 13px;">Стр. <?php echo $page; ?> из <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $search ? '&q=' . urlencode($search) : ''; ?>" class="btn btn-secondary btn-sm">&rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

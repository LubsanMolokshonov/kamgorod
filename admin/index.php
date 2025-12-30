<?php
/**
 * Admin Dashboard
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';

$pageTitle = 'Дашборд';

// Get statistics
$stats = [];

// Total users
$stmt = $db->query("SELECT COUNT(*) as count FROM users");
$stats['users'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total competitions
$stmt = $db->query("SELECT COUNT(*) as count FROM competitions WHERE is_active = 1");
$stats['competitions'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total registrations
$stmt = $db->query("SELECT COUNT(*) as count FROM registrations");
$stats['registrations'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Paid registrations
$stmt = $db->query("SELECT COUNT(*) as count FROM registrations WHERE status IN ('paid', 'diploma_ready')");
$stats['paid_registrations'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Total revenue (sum of paid registrations)
$stmt = $db->query("
    SELECT SUM(c.price) as total
    FROM registrations r
    JOIN competitions c ON r.competition_id = c.id
    WHERE r.status IN ('paid', 'diploma_ready')
");
$stats['revenue'] = $stmt->fetch(PDO::FETCH_ASSOC)['total'] ?? 0;

// Generated diplomas
$stmt = $db->query("SELECT COUNT(*) as count FROM diplomas");
$stats['diplomas'] = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

// Recent registrations (last 10)
$recentRegistrations = $db->query("
    SELECT
        r.*,
        u.full_name,
        u.email,
        c.title as competition_name
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN competitions c ON r.competition_id = c.id
    ORDER BY r.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// Popular competitions
$popularCompetitions = $db->query("
    SELECT
        c.title,
        c.price,
        COUNT(r.id) as registration_count,
        SUM(CASE WHEN r.status IN ('paid', 'diploma_ready') THEN 1 ELSE 0 END) as paid_count
    FROM competitions c
    LEFT JOIN registrations r ON c.id = r.competition_id
    WHERE c.is_active = 1
    GROUP BY c.id
    ORDER BY registration_count DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/includes/header.php';
?>

<div class="page-header">
    <h1>Дашборд</h1>
    <p>Общая статистика платформы</p>
</div>

<!-- Statistics Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Всего пользователей</div>
                <div class="stat-value"><?php echo number_format($stats['users'], 0, ',', ' '); ?></div>
            </div>
            <div class="stat-icon">👥</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Активные конкурсы</div>
                <div class="stat-value"><?php echo number_format($stats['competitions'], 0, ',', ' '); ?></div>
            </div>
            <div class="stat-icon">🏆</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Всего регистраций</div>
                <div class="stat-value"><?php echo number_format($stats['registrations'], 0, ',', ' '); ?></div>
                <div class="stat-change positive">
                    Оплачено: <?php echo $stats['paid_registrations']; ?>
                </div>
            </div>
            <div class="stat-icon">📝</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Общий доход</div>
                <div class="stat-value"><?php echo number_format($stats['revenue'], 0, ',', ' '); ?> ₽</div>
            </div>
            <div class="stat-icon">💰</div>
        </div>
    </div>

    <div class="stat-card">
        <div class="stat-card-header">
            <div>
                <div class="stat-label">Сгенерировано дипломов</div>
                <div class="stat-value"><?php echo number_format($stats['diplomas'], 0, ',', ' '); ?></div>
            </div>
            <div class="stat-icon">📄</div>
        </div>
    </div>
</div>

<!-- Recent Registrations -->
<div class="content-card">
    <div class="card-header">
        <h2>Последние регистрации</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($recentRegistrations)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📋</div>
                <h3>Нет регистраций</h3>
                <p>Регистрации появятся здесь</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Участник</th>
                        <th>Email</th>
                        <th>Конкурс</th>
                        <th>Номинация</th>
                        <th>Статус</th>
                        <th>Дата</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentRegistrations as $reg): ?>
                        <tr>
                            <td>#<?php echo $reg['id']; ?></td>
                            <td><?php echo htmlspecialchars($reg['full_name']); ?></td>
                            <td><?php echo htmlspecialchars($reg['email']); ?></td>
                            <td><?php echo htmlspecialchars($reg['competition_name']); ?></td>
                            <td><?php echo htmlspecialchars($reg['nomination']); ?></td>
                            <td>
                                <?php
                                $statusBadges = [
                                    'pending' => 'badge-warning',
                                    'paid' => 'badge-success',
                                    'diploma_ready' => 'badge-info'
                                ];
                                $statusNames = [
                                    'pending' => 'Ожидание',
                                    'paid' => 'Оплачено',
                                    'diploma_ready' => 'Диплом готов'
                                ];
                                $badgeClass = $statusBadges[$reg['status']] ?? 'badge-warning';
                                $statusName = $statusNames[$reg['status']] ?? $reg['status'];
                                ?>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo $statusName; ?>
                                </span>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($reg['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- Popular Competitions -->
<div class="content-card">
    <div class="card-header">
        <h2>Популярные конкурсы</h2>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($popularCompetitions)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏆</div>
                <h3>Нет конкурсов</h3>
                <p>Создайте первый конкурс</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Название конкурса</th>
                        <th>Цена</th>
                        <th>Всего регистраций</th>
                        <th>Оплачено</th>
                        <th>Доход</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($popularCompetitions as $comp): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($comp['title']); ?></td>
                            <td><?php echo number_format($comp['price'], 0, ',', ' '); ?> ₽</td>
                            <td><?php echo $comp['registration_count']; ?></td>
                            <td><?php echo $comp['paid_count']; ?></td>
                            <td><?php echo number_format($comp['paid_count'] * $comp['price'], 0, ',', ' '); ?> ₽</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>

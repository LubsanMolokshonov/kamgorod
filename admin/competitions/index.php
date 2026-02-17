<?php
/**
 * Competitions Management - List
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Конкурсы';

// Get all competitions
$stmt = $db->query("
    SELECT c.*,
           COUNT(r.id) as registration_count,
           SUM(CASE WHEN r.status IN ('paid', 'diploma_ready') THEN 1 ELSE 0 END) as paid_count
    FROM competitions c
    LEFT JOIN registrations r ON c.id = r.competition_id
    GROUP BY c.id
    ORDER BY c.display_order ASC, c.created_at DESC
");
$competitions = $stmt->fetchAll(PDO::FETCH_ASSOC);

$categoryNames = [
    'methodology' => 'Методика',
    'extracurricular' => 'Внеурочная деятельность',
    'student_projects' => 'Проекты учащихся',
    'creative' => 'Творчество'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-actions">
        <div>
            <h1>Конкурсы</h1>
            <p>Управление конкурсами платформы</p>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($competitions)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🏆</div>
                <h3>Нет конкурсов</h3>
                <p>Создайте первый конкурс</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Категория</th>
                        <th>Цена</th>
                        <th>Регистраций</th>
                        <th>Оплачено</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($competitions as $comp): ?>
                        <tr>
                            <td>#<?php echo $comp['id']; ?></td>
                            <td><?php echo htmlspecialchars($comp['title']); ?></td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo $categoryNames[$comp['category']] ?? $comp['category']; ?>
                                </span>
                            </td>
                            <td><?php echo number_format($comp['price'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo $comp['registration_count']; ?></td>
                            <td><?php echo $comp['paid_count']; ?></td>
                            <td>
                                <?php if ($comp['is_active']): ?>
                                    <span class="badge badge-success">Активен</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/competitions/edit.php?id=<?php echo $comp['id']; ?>" class="btn btn-secondary btn-sm">Редактировать</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

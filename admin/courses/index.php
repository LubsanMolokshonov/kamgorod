<?php
/**
 * Courses Management - List
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Курсы';

// Get all courses with stats
$stmt = $db->query("
    SELECT c.*,
           COUNT(ce.id) as enrollment_count,
           SUM(CASE WHEN ce.status = 'paid' THEN 1 ELSE 0 END) as paid_count
    FROM courses c
    LEFT JOIN course_enrollments ce ON c.id = ce.course_id
    GROUP BY c.id
    ORDER BY c.display_order ASC, c.created_at DESC
");
$courses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$programTypeNames = [
    'kpk' => 'КПК',
    'pp' => 'Переподготовка'
];

$programTypeBadges = [
    'kpk' => 'badge-info',
    'pp' => 'badge-purple'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-actions">
        <div>
            <h1>Курсы</h1>
            <p>Управление курсами повышения квалификации и переподготовки</p>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($courses)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📚</div>
                <h3>Нет курсов</h3>
                <p>Создайте первый курс</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Группа</th>
                        <th>Часы</th>
                        <th>Цена</th>
                        <th>Заявок</th>
                        <th>Оплачено</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($courses as $course): ?>
                        <tr>
                            <td>#<?php echo $course['id']; ?></td>
                            <td><?php echo htmlspecialchars($course['title']); ?></td>
                            <td>
                                <span class="badge <?php echo $programTypeBadges[$course['program_type']] ?? 'badge-info'; ?>">
                                    <?php echo $programTypeNames[$course['program_type']] ?? $course['program_type']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($course['course_group'] ?? '—'); ?></td>
                            <td><?php echo $course['hours'] ?? '—'; ?></td>
                            <td><?php echo number_format($course['price'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo $course['enrollment_count']; ?></td>
                            <td><?php echo $course['paid_count']; ?></td>
                            <td>
                                <?php if ($course['is_active']): ?>
                                    <span class="badge badge-success">Активен</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/courses/edit.php?id=<?php echo $course['id']; ?>" class="btn btn-secondary btn-sm">Редактировать</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

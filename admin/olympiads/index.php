<?php
/**
 * Olympiads Management - List
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Олимпиады';

// Get all olympiads with stats
$stmt = $db->query("
    SELECT o.*,
           COUNT(r.id) as registration_count,
           SUM(CASE WHEN r.status IN ('paid', 'diploma_ready') THEN 1 ELSE 0 END) as paid_count
    FROM olympiads o
    LEFT JOIN olympiad_registrations r ON o.id = r.olympiad_id
    GROUP BY o.id
    ORDER BY o.display_order ASC, o.created_at DESC
");
$olympiads = $stmt->fetchAll(PDO::FETCH_ASSOC);

$audienceNames = [
    'teachers' => 'Педагогам',
    'preschool' => 'Дошкольникам',
    'school' => 'Школьникам',
    'spo' => 'Студентам СПО'
];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div class="page-header-actions">
        <div>
            <h1>Олимпиады</h1>
            <p>Управление олимпиадами платформы</p>
        </div>
    </div>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($olympiads)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">🎓</div>
                <h3>Нет олимпиад</h3>
                <p>Создайте первую олимпиаду</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Аудитория</th>
                        <th>Предмет</th>
                        <th>Цена диплома</th>
                        <th>Регистраций</th>
                        <th>Оплачено</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($olympiads as $olympiad): ?>
                        <tr>
                            <td>#<?php echo $olympiad['id']; ?></td>
                            <td><?php echo htmlspecialchars($olympiad['title']); ?></td>
                            <td>
                                <span class="badge badge-info">
                                    <?php echo $audienceNames[$olympiad['target_audience']] ?? $olympiad['target_audience']; ?>
                                </span>
                            </td>
                            <td><?php echo htmlspecialchars($olympiad['subject'] ?? '—'); ?></td>
                            <td><?php echo number_format($olympiad['diploma_price'], 0, ',', ' '); ?> &#8381;</td>
                            <td><?php echo $olympiad['registration_count']; ?></td>
                            <td><?php echo $olympiad['paid_count']; ?></td>
                            <td>
                                <?php if ($olympiad['is_active']): ?>
                                    <span class="badge badge-success">Активна</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Неактивна</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/olympiads/edit.php?id=<?php echo $olympiad['id']; ?>" class="btn btn-secondary btn-sm">Редактировать</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

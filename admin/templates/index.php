<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Diploma Templates Management - List
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Шаблоны дипломов';

// Get all diploma templates
$stmt = $db->query("
    SELECT dt.*,
           COUNT(d.id) as usage_count
    FROM diploma_templates dt
    LEFT JOIN diplomas d ON dt.id = d.template_id
    GROUP BY dt.id
    ORDER BY dt.id ASC
");
$templates = $stmt->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Шаблоны дипломов</h1>
    <p>Управление шаблонами дипломов и сертификатов</p>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📄</div>
                <h3>Нет шаблонов</h3>
                <p>Шаблоны дипломов пока не добавлены</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Использований</th>
                        <th>Дата создания</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $tpl): ?>
                        <tr>
                            <td>#<?php echo $tpl['id']; ?></td>
                            <td><?php echo htmlspecialchars($tpl['name'] ?? $tpl['title'] ?? 'Шаблон #' . $tpl['id']); ?></td>
                            <td><?php echo $tpl['usage_count']; ?></td>
                            <td><?php echo isset($tpl['created_at']) ? date('d.m.Y', strtotime($tpl['created_at'])) : '&mdash;'; ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

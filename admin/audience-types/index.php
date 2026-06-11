<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Audience Types Management - List View
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AudienceType.php';

$pageTitle = 'Управление типами аудитории';

// Handle delete action
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    $audienceTypeObj = new AudienceType($db);
    $id = (int)$_GET['id'];

    try {
        $audienceTypeObj->delete($id);
        $successMessage = 'Тип аудитории успешно удалён';
    } catch (Exception $e) {
        $errorMessage = 'Ошибка при удалении: ' . $e->getMessage();
    }
}

// Get all audience types
$audienceTypeObj = new AudienceType($db);
$audienceTypes = $audienceTypeObj->getAll(false); // Get all, including inactive

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Типы аудитории</h1>
        <p>Управление типами учреждений (ДОУ, школы, СПО)</p>
    </div>
    <a href="/admin/audience-types/create.php" class="btn btn-primary">
        <span>➕</span> Добавить тип
    </a>
</div>

<?php if (isset($successMessage)): ?>
<div class="alert alert-success">
    <?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if (isset($errorMessage)): ?>
<div class="alert alert-error">
    <?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<div class="content-card">
    <?php if (empty($audienceTypes)): ?>
        <div class="empty-state">
            <p>Типы аудитории не найдены</p>
            <a href="/admin/audience-types/create.php" class="btn btn-primary">Создать первый тип</a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 200px;">Slug</th>
                        <th>Название</th>
                        <th style="width: 120px; text-align: center;">Порядок</th>
                        <th style="width: 120px; text-align: center;">Специализаций</th>
                        <th style="width: 120px; text-align: center;">Конкурсов</th>
                        <th style="width: 100px; text-align: center;">Статус</th>
                        <th style="width: 200px; text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($audienceTypes as $type): ?>
                        <?php
                        // Count specializations
                        $specializationCount = count($audienceTypeObj->getSpecializations($type['id'], false));

                        // Count competitions
                        $competitionCount = $audienceTypeObj->getCompetitionCount($type['id']);
                        ?>
                        <tr>
                            <td><?php echo $type['id']; ?></td>
                            <td>
                                <code><?php echo htmlspecialchars($type['slug']); ?></code>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($type['name']); ?></strong>
                                <?php if (!empty($type['description'])): ?>
                                    <br>
                                    <small style="color: var(--text-medium);">
                                        <?php echo htmlspecialchars(mb_substr($type['description'], 0, 80)); ?>
                                        <?php echo mb_strlen($type['description']) > 80 ? '...' : ''; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $type['display_order']; ?>
                            </td>
                            <td style="text-align: center;">
                                <a href="/admin/audience-types/specializations.php?type_id=<?php echo $type['id']; ?>"
                                   class="link-primary">
                                    <?php echo $specializationCount; ?>
                                </a>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $competitionCount; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($type['is_active']): ?>
                                    <span class="badge badge-success">Активен</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="btn-group">
                                    <a href="/admin/audience-types/specializations.php?type_id=<?php echo $type['id']; ?>"
                                       class="btn btn-sm btn-secondary"
                                       title="Управление специализациями">
                                        📚
                                    </a>
                                    <a href="/admin/audience-types/edit.php?id=<?php echo $type['id']; ?>"
                                       class="btn btn-sm btn-primary"
                                       title="Редактировать">
                                        ✏️
                                    </a>
                                    <a href="/admin/audience-types/index.php?action=delete&id=<?php echo $type['id']; ?>"
                                       class="btn btn-sm btn-danger"
                                       title="Удалить"
                                       onclick="return confirm('Вы уверены, что хотите удалить этот тип аудитории? Это также удалит все связанные специализации.');">
                                        🗑️
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<style>
.btn-group {
    display: flex;
    gap: 6px;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 14px;
    min-width: auto;
}

.link-primary {
    color: var(--primary-purple);
    text-decoration: none;
    font-weight: 600;
}

.link-primary:hover {
    text-decoration: underline;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.badge-success {
    background: #10b981;
    color: white;
}

.badge-secondary {
    background: #6b7280;
    color: white;
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
}

.empty-state p {
    font-size: 16px;
    color: var(--text-medium);
    margin-bottom: 24px;
}

.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 14px;
}

.alert-success {
    background: #d1fae5;
    color: #065f46;
    border: 1px solid #10b981;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

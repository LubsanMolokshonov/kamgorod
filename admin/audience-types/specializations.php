<?php
/**
 * Audience Types Management - Manage Specializations
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AudienceType.php';
require_once __DIR__ . '/../../classes/AudienceSpecialization.php';

$pageTitle = 'Управление специализациями';

// Get type ID from URL
$typeId = (int)($_GET['type_id'] ?? 0);

if ($typeId === 0) {
    header('Location: /admin/audience-types/index.php');
    exit;
}

$audienceTypeObj = new AudienceType($db);
$audienceType = $audienceTypeObj->getById($typeId);

if (!$audienceType) {
    header('Location: /admin/audience-types/index.php');
    exit;
}

$specializationObj = new AudienceSpecialization($db);

// Handle actions
$successMessage = null;
$errorMessage = null;

// Delete specialization
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['spec_id'])) {
    $specId = (int)$_GET['spec_id'];
    try {
        $specializationObj->delete($specId);
        $successMessage = 'Специализация успешно удалена';
    } catch (Exception $e) {
        $errorMessage = 'Ошибка при удалении: ' . $e->getMessage();
    }
}

// Add new specialization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $data = [
        'audience_type_id' => $typeId,
        'slug' => trim($_POST['slug'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'display_order' => (int)($_POST['display_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // Validation
    $errors = [];

    if (empty($data['slug'])) {
        $errors[] = 'Slug обязателен';
    } elseif (!preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
        $errors[] = 'Slug может содержать только латинские буквы, цифры и дефисы';
    }

    if (empty($data['name'])) {
        $errors[] = 'Название обязательно';
    }

    if (empty($errors)) {
        try {
            $specializationObj->create($data);
            $successMessage = 'Специализация успешно создана';
        } catch (Exception $e) {
            $errorMessage = 'Ошибка при создании: ' . $e->getMessage();
        }
    } else {
        $errorMessage = implode(', ', $errors);
    }
}

// Update specialization
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update') {
    $specId = (int)$_POST['spec_id'];
    $data = [
        'slug' => trim($_POST['slug'] ?? ''),
        'name' => trim($_POST['name'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'display_order' => (int)($_POST['display_order'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // Validation
    $errors = [];

    if (empty($data['slug'])) {
        $errors[] = 'Slug обязателен';
    } elseif (!preg_match('/^[a-z0-9\-]+$/', $data['slug'])) {
        $errors[] = 'Slug может содержать только латинские буквы, цифры и дефисы';
    }

    if (empty($data['name'])) {
        $errors[] = 'Название обязательно';
    }

    if (empty($errors)) {
        try {
            $specializationObj->update($specId, $data);
            $successMessage = 'Специализация успешно обновлена';
        } catch (Exception $e) {
            $errorMessage = 'Ошибка при обновлении: ' . $e->getMessage();
        }
    } else {
        $errorMessage = implode(', ', $errors);
    }
}

// Get all specializations for this type
$specializations = $audienceTypeObj->getSpecializations($typeId, false);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Специализации: <?php echo htmlspecialchars($audienceType['name']); ?></h1>
        <p>Управление специализациями и предметами</p>
    </div>
    <div style="display: flex; gap: 12px;">
        <button onclick="openCreateModal()" class="btn btn-primary">
            ➕ Добавить специализацию
        </button>
        <a href="/admin/audience-types/index.php" class="btn btn-secondary">
            ← Назад к типам
        </a>
    </div>
</div>

<?php if ($successMessage): ?>
<div class="alert alert-success">
    <?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

<?php if ($errorMessage): ?>
<div class="alert alert-error">
    <?php echo htmlspecialchars($errorMessage); ?>
</div>
<?php endif; ?>

<div class="content-card">
    <?php if (empty($specializations)): ?>
        <div class="empty-state">
            <p>Специализации не найдены</p>
            <button onclick="openCreateModal()" class="btn btn-primary">Создать первую специализацию</button>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 180px;">Slug</th>
                        <th>Название</th>
                        <th style="width: 100px; text-align: center;">Порядок</th>
                        <th style="width: 100px; text-align: center;">Конкурсов</th>
                        <th style="width: 100px; text-align: center;">Статус</th>
                        <th style="width: 150px; text-align: right;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($specializations as $spec): ?>
                        <?php
                        $competitionCount = $specializationObj->getCompetitionCount($spec['id']);
                        ?>
                        <tr>
                            <td><?php echo $spec['id']; ?></td>
                            <td>
                                <code><?php echo htmlspecialchars($spec['slug']); ?></code>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($spec['name']); ?></strong>
                                <?php if (!empty($spec['description'])): ?>
                                    <br>
                                    <small style="color: var(--text-medium);">
                                        <?php echo htmlspecialchars(mb_substr($spec['description'], 0, 60)); ?>
                                        <?php echo mb_strlen($spec['description']) > 60 ? '...' : ''; ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $spec['display_order']; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php echo $competitionCount; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($spec['is_active']): ?>
                                    <span class="badge badge-success">Активна</span>
                                <?php else: ?>
                                    <span class="badge badge-secondary">Неактивна</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="btn-group">
                                    <button onclick='openEditModal(<?php echo json_encode($spec, JSON_UNESCAPED_UNICODE); ?>)'
                                            class="btn btn-sm btn-primary"
                                            title="Редактировать">
                                        ✏️
                                    </button>
                                    <a href="?type_id=<?php echo $typeId; ?>&action=delete&spec_id=<?php echo $spec['id']; ?>"
                                       class="btn btn-sm btn-danger"
                                       title="Удалить"
                                       onclick="return confirm('Вы уверены, что хотите удалить эту специализацию?');">
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

<!-- Create/Edit Modal -->
<div id="specModal" class="modal" style="display: none;">
    <div class="modal-overlay" onclick="closeModal()"></div>
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2 id="modalTitle">Добавить специализацию</h2>
            <button class="modal-close" onclick="closeModal()">✕</button>
        </div>
        <form id="specForm" method="POST" action="">
            <input type="hidden" name="action" id="formAction" value="create">
            <input type="hidden" name="spec_id" id="specId" value="">

            <div class="modal-body">
                <div class="form-group">
                    <label for="spec_name" class="form-label required">Название</label>
                    <input type="text" id="spec_name" name="name" class="form-input" required>
                </div>

                <div class="form-group">
                    <label for="spec_slug" class="form-label required">Slug</label>
                    <input type="text" id="spec_slug" name="slug" class="form-input" pattern="[a-z0-9\-]+" required>
                    <p class="form-help">Только латинские буквы, цифры и дефисы</p>
                </div>

                <div class="form-group">
                    <label for="spec_description" class="form-label">Описание</label>
                    <textarea id="spec_description" name="description" class="form-textarea" rows="3"></textarea>
                </div>

                <div class="form-group">
                    <label for="spec_order" class="form-label">Порядок</label>
                    <input type="number" id="spec_order" name="display_order" class="form-input" value="0" min="0">
                </div>

                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" id="spec_active" name="is_active" value="1" checked>
                        <span>Активна</span>
                    </label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="submit" class="btn btn-primary">Сохранить</button>
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Отмена</button>
            </div>
        </form>
    </div>
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

.modal {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    z-index: 9999;
    display: flex;
    align-items: center;
    justify-content: center;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    position: relative;
    background: white;
    border-radius: 16px;
    width: 90%;
    max-width: 600px;
    max-height: 90vh;
    overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
}

.modal-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 24px;
    border-bottom: 1px solid #e5e7eb;
}

.modal-header h2 {
    margin: 0;
    font-size: 20px;
}

.modal-close {
    background: none;
    border: none;
    font-size: 24px;
    cursor: pointer;
    color: var(--text-medium);
    padding: 0;
    width: 32px;
    height: 32px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 8px;
    transition: all 0.2s;
}

.modal-close:hover {
    background: #f3f4f6;
    color: var(--text-dark);
}

.modal-body {
    padding: 24px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 12px;
    padding: 24px;
    border-top: 1px solid #e5e7eb;
}

.form-group {
    margin-bottom: 20px;
}

.form-label {
    display: block;
    font-weight: 600;
    font-size: 14px;
    margin-bottom: 8px;
    color: var(--text-dark);
}

.form-label.required::after {
    content: ' *';
    color: #ef4444;
}

.form-input,
.form-textarea {
    width: 100%;
    padding: 10px 14px;
    font-size: 14px;
    border: 2px solid #e5e7eb;
    border-radius: 8px;
    font-family: inherit;
    transition: border-color 0.2s ease;
}

.form-input:focus,
.form-textarea:focus {
    outline: none;
    border-color: var(--primary-purple);
}

.form-textarea {
    resize: vertical;
}

.form-help {
    margin-top: 4px;
    font-size: 12px;
    color: var(--text-medium);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 14px;
}

.checkbox-label input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}
</style>

<script>
function openCreateModal() {
    document.getElementById('modalTitle').textContent = 'Добавить специализацию';
    document.getElementById('formAction').value = 'create';
    document.getElementById('specId').value = '';
    document.getElementById('spec_name').value = '';
    document.getElementById('spec_slug').value = '';
    document.getElementById('spec_description').value = '';
    document.getElementById('spec_order').value = '0';
    document.getElementById('spec_active').checked = true;
    document.getElementById('specModal').style.display = 'flex';
}

function openEditModal(spec) {
    document.getElementById('modalTitle').textContent = 'Редактировать специализацию';
    document.getElementById('formAction').value = 'update';
    document.getElementById('specId').value = spec.id;
    document.getElementById('spec_name').value = spec.name;
    document.getElementById('spec_slug').value = spec.slug;
    document.getElementById('spec_description').value = spec.description || '';
    document.getElementById('spec_order').value = spec.display_order;
    document.getElementById('spec_active').checked = spec.is_active == 1;
    document.getElementById('specModal').style.display = 'flex';
}

function closeModal() {
    document.getElementById('specModal').style.display = 'none';
}

// Auto-generate slug from name
document.getElementById('spec_name').addEventListener('input', function(e) {
    const slugInput = document.getElementById('spec_slug');

    // Only auto-generate for new items (when creating)
    if (document.getElementById('formAction').value === 'create' || !slugInput.value) {
        let slug = e.target.value
            .toLowerCase()
            .replace(/[а-яё]/g, function(char) {
                const translit = {
                    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 'е': 'e', 'ё': 'yo',
                    'ж': 'zh', 'з': 'z', 'и': 'i', 'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm',
                    'н': 'n', 'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 'у': 'u',
                    'ф': 'f', 'х': 'h', 'ц': 'ts', 'ч': 'ch', 'ш': 'sh', 'щ': 'sch',
                    'ъ': '', 'ы': 'y', 'ь': '', 'э': 'e', 'ю': 'yu', 'я': 'ya'
                };
                return translit[char] || char;
            })
            .replace(/[^a-z0-9\s\-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-')
            .replace(/^-|-$/g, '');

        slugInput.value = slug;
    }
});

// Close modal on Escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('specModal').style.display === 'flex') {
        closeModal();
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

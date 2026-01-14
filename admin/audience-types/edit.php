<?php
/**
 * Audience Types Management - Edit Existing Type
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AudienceType.php';

$pageTitle = 'Редактировать тип аудитории';

// Get ID from URL
$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {
    header('Location: /admin/audience-types/index.php');
    exit;
}

$audienceTypeObj = new AudienceType($db);
$audienceType = $audienceTypeObj->getById($id);

if (!$audienceType) {
    header('Location: /admin/audience-types/index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
            $audienceTypeObj->update($id, $data);

            // Redirect to list with success message
            header('Location: /admin/audience-types/index.php?updated=' . $id);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Ошибка при обновлении: ' . $e->getMessage();
        }
    }

    // Use POST data for form if there were errors
    $audienceType = array_merge($audienceType, $data);
}

// Get statistics
$specializationCount = count($audienceTypeObj->getSpecializations($id, false));
$competitionCount = $audienceTypeObj->getCompetitionCount($id);

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Редактировать тип аудитории</h1>
        <p>ID: <?php echo $id; ?> | Специализаций: <?php echo $specializationCount; ?> | Конкурсов: <?php echo $competitionCount; ?></p>
    </div>
    <a href="/admin/audience-types/index.php" class="btn btn-secondary">
        ← Назад к списку
    </a>
</div>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Ошибки:</strong>
    <ul style="margin: 8px 0 0 20px; padding: 0;">
        <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="content-card">
    <form method="POST" action="" class="form">
        <div class="form-group">
            <label for="name" class="form-label required">Название типа</label>
            <input type="text"
                   id="name"
                   name="name"
                   class="form-input"
                   value="<?php echo htmlspecialchars($audienceType['name']); ?>"
                   placeholder="Например: ДОУ (Дошкольное образовательное учреждение)"
                   required>
            <p class="form-help">Полное название типа учреждения</p>
        </div>

        <div class="form-group">
            <label for="slug" class="form-label required">Slug (URL)</label>
            <input type="text"
                   id="slug"
                   name="slug"
                   class="form-input"
                   value="<?php echo htmlspecialchars($audienceType['slug']); ?>"
                   placeholder="Например: dou"
                   pattern="[a-z0-9\-]+"
                   required>
            <p class="form-help">
                Только латинские буквы, цифры и дефисы. URL: <strong>/<?php echo htmlspecialchars($audienceType['slug']); ?></strong>
                <?php if ($competitionCount > 0): ?>
                    <br><span style="color: #ef4444;">⚠️ Внимание: изменение slug может нарушить существующие ссылки!</span>
                <?php endif; ?>
            </p>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Описание</label>
            <textarea id="description"
                      name="description"
                      class="form-textarea"
                      rows="4"
                      placeholder="Краткое описание для кого предназначены конкурсы этого типа"><?php echo htmlspecialchars($audienceType['description']); ?></textarea>
            <p class="form-help">Описание отображается на посадочной странице типа</p>
        </div>

        <div class="form-group">
            <label for="display_order" class="form-label">Порядок отображения</label>
            <input type="number"
                   id="display_order"
                   name="display_order"
                   class="form-input"
                   value="<?php echo htmlspecialchars($audienceType['display_order']); ?>"
                   min="0"
                   step="1">
            <p class="form-help">Меньшее число = выше в списке</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox"
                       name="is_active"
                       value="1"
                       <?php echo $audienceType['is_active'] ? 'checked' : ''; ?>>
                <span>Активен (отображается на сайте)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" name="submit" class="btn btn-primary">
                Сохранить изменения
            </button>
            <a href="/admin/audience-types/index.php" class="btn btn-secondary">
                Отмена
            </a>
            <a href="/admin/audience-types/specializations.php?type_id=<?php echo $id; ?>" class="btn btn-outline">
                📚 Управление специализациями
            </a>
        </div>
    </form>
</div>

<style>
.form {
    max-width: 800px;
}

.form-group {
    margin-bottom: 24px;
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
    padding: 12px 16px;
    font-size: 15px;
    border: 2px solid #e5e7eb;
    border-radius: 10px;
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
    min-height: 100px;
}

.form-help {
    margin-top: 6px;
    font-size: 13px;
    color: var(--text-medium);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    font-size: 15px;
}

.checkbox-label input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 32px;
    padding-top: 24px;
    border-top: 1px solid #e5e7eb;
}

.alert {
    padding: 16px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    font-size: 14px;
}

.alert-error {
    background: #fee2e2;
    color: #991b1b;
    border: 1px solid #ef4444;
}

.alert-error strong {
    display: block;
    margin-bottom: 8px;
}

.btn-outline {
    background: white;
    color: var(--primary-purple);
    border: 2px solid var(--primary-purple);
}

.btn-outline:hover {
    background: var(--light-purple);
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

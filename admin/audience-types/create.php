<?php
/**
 * Audience Types Management - Create New Type
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/AudienceType.php';

$pageTitle = 'Создать тип аудитории';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $audienceTypeObj = new AudienceType($db);

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
            $newId = $audienceTypeObj->create($data);

            // Redirect to list with success message
            header('Location: /admin/audience-types/index.php?created=' . $newId);
            exit;
        } catch (Exception $e) {
            $errors[] = 'Ошибка при создании: ' . $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Создать тип аудитории</h1>
        <p>Добавьте новый тип учреждения</p>
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
                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
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
                   value="<?php echo htmlspecialchars($_POST['slug'] ?? ''); ?>"
                   placeholder="Например: dou"
                   pattern="[a-z0-9\-]+"
                   required>
            <p class="form-help">Только латинские буквы, цифры и дефисы. Будет использоваться в URL: /slug</p>
        </div>

        <div class="form-group">
            <label for="description" class="form-label">Описание</label>
            <textarea id="description"
                      name="description"
                      class="form-textarea"
                      rows="4"
                      placeholder="Краткое описание для кого предназначены конкурсы этого типа"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            <p class="form-help">Описание отображается на посадочной странице типа</p>
        </div>

        <div class="form-group">
            <label for="display_order" class="form-label">Порядок отображения</label>
            <input type="number"
                   id="display_order"
                   name="display_order"
                   class="form-input"
                   value="<?php echo htmlspecialchars($_POST['display_order'] ?? '0'); ?>"
                   min="0"
                   step="1">
            <p class="form-help">Меньшее число = выше в списке</p>
        </div>

        <div class="form-group">
            <label class="checkbox-label">
                <input type="checkbox"
                       name="is_active"
                       value="1"
                       <?php echo isset($_POST['is_active']) || !isset($_POST['submit']) ? 'checked' : ''; ?>>
                <span>Активен (отображается на сайте)</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" name="submit" class="btn btn-primary">
                Создать тип аудитории
            </button>
            <a href="/admin/audience-types/index.php" class="btn btn-secondary">
                Отмена
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
</style>

<script>
// Auto-generate slug from name
document.getElementById('name').addEventListener('input', function(e) {
    const slugInput = document.getElementById('slug');

    // Only auto-generate if slug is empty or hasn't been manually edited
    if (!slugInput.dataset.manuallyEdited) {
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

// Mark slug as manually edited if user types in it directly
document.getElementById('slug').addEventListener('input', function() {
    this.dataset.manuallyEdited = 'true';
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

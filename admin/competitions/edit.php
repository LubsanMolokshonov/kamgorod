<?php
/**
 * Competitions Management - Edit Competition
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Competition.php';
require_once __DIR__ . '/../../classes/AudienceType.php';
require_once __DIR__ . '/../../classes/AudienceSpecialization.php';

$pageTitle = 'Редактировать конкурс';

// Get competition ID
$id = (int)($_GET['id'] ?? 0);

if ($id === 0) {
    header('Location: /admin/index.php');
    exit;
}

$competitionObj = new Competition($db);
$competition = $competitionObj->getById($id);

if (!$competition) {
    header('Location: /admin/index.php');
    exit;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Update basic competition data
    $updateData = [
        'title' => trim($_POST['title'] ?? ''),
        'slug' => trim($_POST['slug'] ?? ''),
        'description' => trim($_POST['description'] ?? ''),
        'category' => $_POST['category'] ?? 'methodology',
        'target_participants' => trim($_POST['target_participants'] ?? ''),
        'target_participants_genitive' => trim($_POST['target_participants_genitive'] ?? ''),
        'nomination_options' => trim($_POST['nomination_options'] ?? ''),
        'award_structure' => trim($_POST['award_structure'] ?? ''),
        'academic_year' => trim($_POST['academic_year'] ?? ''),
        'price' => (int)($_POST['price'] ?? 0),
        'is_active' => isset($_POST['is_active']) ? 1 : 0
    ];

    // Validation
    $errors = [];

    if (empty($updateData['title'])) {
        $errors[] = 'Название обязательно';
    }

    if (empty($updateData['slug'])) {
        $errors[] = 'Slug обязателен';
    }

    if (empty($errors)) {
        try {
            // Update competition
            $competitionObj->update($id, $updateData);

            // Update audience types
            if (isset($_POST['audience_types']) && is_array($_POST['audience_types'])) {
                $audienceTypeIds = array_map('intval', $_POST['audience_types']);
                $competitionObj->setAudienceTypes($id, $audienceTypeIds);
            } else {
                // Clear all audience types if none selected
                $competitionObj->setAudienceTypes($id, []);
            }

            // Update specializations
            if (isset($_POST['specializations']) && is_array($_POST['specializations'])) {
                $specializationIds = array_map('intval', $_POST['specializations']);
                $competitionObj->setSpecializations($id, $specializationIds);
            } else {
                // Clear all specializations if none selected
                $competitionObj->setSpecializations($id, []);
            }

            // Redirect with success
            $successMessage = 'Конкурс успешно обновлён';

            // Reload competition data
            $competition = $competitionObj->getById($id);
        } catch (Exception $e) {
            $errors[] = 'Ошибка при обновлении: ' . $e->getMessage();
        }
    }
}

// Get current audience types and specializations for this competition
$selectedAudienceTypes = $competitionObj->getAudienceTypes($id);
$selectedSpecializations = $competitionObj->getSpecializations($id);

// Convert to ID arrays for easier checking
$selectedTypeIds = array_column($selectedAudienceTypes, 'id');
$selectedSpecIds = array_column($selectedSpecializations, 'id');

// Get all available audience types and specializations
$audienceTypeObj = new AudienceType($db);
$allAudienceTypes = $audienceTypeObj->getAll(false);

// Get specializations grouped by audience type
$specializationsByType = [];
foreach ($allAudienceTypes as $type) {
    $specializationsByType[$type['id']] = $audienceTypeObj->getSpecializations($type['id'], false);
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Редактировать конкурс</h1>
        <p>ID: <?php echo $id; ?> | <?php echo htmlspecialchars($competition['title']); ?></p>
    </div>
    <a href="/admin/index.php" class="btn btn-secondary">
        ← Назад
    </a>
</div>

<?php if (isset($successMessage)): ?>
<div class="alert alert-success">
    <?php echo htmlspecialchars($successMessage); ?>
</div>
<?php endif; ?>

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

<form method="POST" action="" class="admin-form">
    <!-- Basic Information -->
    <div class="content-card" style="margin-bottom: 24px;">
        <h2 style="margin-bottom: 24px; font-size: 20px;">Основная информация</h2>

        <div class="form-grid">
            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="title" class="form-label required">Название конкурса</label>
                <input type="text"
                       id="title"
                       name="title"
                       class="form-input"
                       value="<?php echo htmlspecialchars($competition['title']); ?>"
                       required>
            </div>

            <div class="form-group">
                <label for="slug" class="form-label required">Slug (URL)</label>
                <input type="text"
                       id="slug"
                       name="slug"
                       class="form-input"
                       value="<?php echo htmlspecialchars($competition['slug']); ?>"
                       pattern="[a-z0-9\-]+"
                       required>
            </div>

            <div class="form-group">
                <label for="category" class="form-label required">Категория</label>
                <select id="category" name="category" class="form-input" required>
                    <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
                        <option value="<?php echo $cat; ?>"
                                <?php echo $competition['category'] === $cat ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-group">
                <label for="academic_year" class="form-label">Учебный год</label>
                <input type="text"
                       id="academic_year"
                       name="academic_year"
                       class="form-input"
                       value="<?php echo htmlspecialchars($competition['academic_year']); ?>"
                       placeholder="2025-2026">
            </div>

            <div class="form-group">
                <label for="price" class="form-label required">Стоимость (₽)</label>
                <input type="number"
                       id="price"
                       name="price"
                       class="form-input"
                       value="<?php echo $competition['price']; ?>"
                       min="0"
                       step="1"
                       required>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="description" class="form-label">Описание</label>
                <textarea id="description"
                          name="description"
                          class="form-textarea"
                          rows="5"><?php echo htmlspecialchars($competition['description']); ?></textarea>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="target_participants" class="form-label">Целевая аудитория (именительный падеж)</label>
                <textarea id="target_participants"
                          name="target_participants"
                          class="form-textarea"
                          rows="2"><?php echo htmlspecialchars($competition['target_participants']); ?></textarea>
                <p class="form-help">Например: Воспитатели дошкольных образовательных учреждений</p>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="target_participants_genitive" class="form-label">Целевая аудитория (родительный падеж)</label>
                <textarea id="target_participants_genitive"
                          name="target_participants_genitive"
                          class="form-textarea"
                          rows="2"><?php echo htmlspecialchars($competition['target_participants_genitive'] ?? ''); ?></textarea>
                <p class="form-help">Для фразы "Конкурс для...". Например: воспитателей дошкольных образовательных учреждений</p>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="nomination_options" class="form-label">Номинации</label>
                <textarea id="nomination_options"
                          name="nomination_options"
                          class="form-textarea"
                          rows="4"><?php echo htmlspecialchars($competition['nomination_options']); ?></textarea>
                <p class="form-help">Каждая номинация с новой строки</p>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label for="award_structure" class="form-label">Структура наград</label>
                <textarea id="award_structure"
                          name="award_structure"
                          class="form-textarea"
                          rows="3"><?php echo htmlspecialchars($competition['award_structure']); ?></textarea>
            </div>

            <div class="form-group" style="grid-column: 1 / -1;">
                <label class="checkbox-label">
                    <input type="checkbox"
                           name="is_active"
                           value="1"
                           <?php echo $competition['is_active'] ? 'checked' : ''; ?>>
                    <span>Активен (отображается на сайте)</span>
                </label>
            </div>
        </div>
    </div>

    <!-- Audience Segmentation -->
    <div class="content-card" style="margin-bottom: 24px;">
        <h2 style="margin-bottom: 16px; font-size: 20px;">Сегментация аудитории</h2>
        <p style="color: var(--text-medium); margin-bottom: 24px;">
            Выберите типы учреждений и специализации, для которых предназначен этот конкурс
        </p>

        <!-- Audience Types -->
        <div class="form-group" style="margin-bottom: 32px;">
            <label class="form-label">Типы учреждений</label>
            <div class="checkbox-grid">
                <?php foreach ($allAudienceTypes as $type): ?>
                    <label class="checkbox-card">
                        <input type="checkbox"
                               name="audience_types[]"
                               value="<?php echo $type['id']; ?>"
                               class="audience-type-checkbox"
                               data-type-id="<?php echo $type['id']; ?>"
                               <?php echo in_array($type['id'], $selectedTypeIds) ? 'checked' : ''; ?>>
                        <div class="checkbox-card-content">
                            <strong><?php echo htmlspecialchars($type['name']); ?></strong>
                            <?php if (!empty($type['description'])): ?>
                                <small><?php echo htmlspecialchars(mb_substr($type['description'], 0, 80)); ?></small>
                            <?php endif; ?>
                        </div>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Specializations grouped by type -->
        <div class="form-group">
            <label class="form-label">Специализации</label>

            <?php foreach ($allAudienceTypes as $type): ?>
                <?php if (!empty($specializationsByType[$type['id']])): ?>
                    <div class="specialization-group" data-type-id="<?php echo $type['id']; ?>">
                        <h3 class="specialization-group-title"><?php echo htmlspecialchars($type['name']); ?></h3>
                        <div class="checkbox-list">
                            <?php foreach ($specializationsByType[$type['id']] as $spec): ?>
                                <label class="checkbox-label-compact">
                                    <input type="checkbox"
                                           name="specializations[]"
                                           value="<?php echo $spec['id']; ?>"
                                           <?php echo in_array($spec['id'], $selectedSpecIds) ? 'checked' : ''; ?>>
                                    <span><?php echo htmlspecialchars($spec['name']); ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Form Actions -->
    <div class="form-actions">
        <button type="submit" class="btn btn-primary">
            💾 Сохранить изменения
        </button>
        <a href="/admin/index.php" class="btn btn-secondary">
            Отмена
        </a>
        <a href="/pages/competition-detail.php?slug=<?php echo htmlspecialchars($competition['slug']); ?>"
           class="btn btn-outline"
           target="_blank">
            👁️ Просмотр на сайте
        </a>
    </div>
</form>

<style>
.admin-form {
    max-width: 1000px;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
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

.checkbox-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 16px;
}

.checkbox-card {
    display: flex;
    gap: 12px;
    padding: 16px;
    border: 2px solid #e5e7eb;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.2s ease;
}

.checkbox-card:hover {
    border-color: var(--light-purple);
    background: #f9f5ff;
}

.checkbox-card input[type="checkbox"] {
    flex-shrink: 0;
    width: 20px;
    height: 20px;
    margin-top: 2px;
    cursor: pointer;
}

.checkbox-card input[type="checkbox"]:checked + .checkbox-card-content {
    color: var(--primary-purple);
}

.checkbox-card-content {
    flex: 1;
}

.checkbox-card-content strong {
    display: block;
    margin-bottom: 4px;
    font-size: 15px;
}

.checkbox-card-content small {
    display: block;
    font-size: 13px;
    color: var(--text-medium);
    line-height: 1.4;
}

.specialization-group {
    margin-bottom: 24px;
    padding: 20px;
    background: #f9fafb;
    border-radius: 12px;
}

.specialization-group-title {
    font-size: 16px;
    font-weight: 600;
    color: var(--text-dark);
    margin-bottom: 16px;
    padding-bottom: 12px;
    border-bottom: 1px solid #e5e7eb;
}

.checkbox-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 12px;
}

.checkbox-label-compact {
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    font-size: 14px;
    padding: 8px;
    border-radius: 8px;
    transition: background 0.2s;
}

.checkbox-label-compact:hover {
    background: white;
}

.checkbox-label-compact input[type="checkbox"] {
    width: 16px;
    height: 16px;
    cursor: pointer;
}

.form-actions {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 32px;
    padding: 24px;
    background: #f9fafb;
    border-radius: 12px;
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

@media (max-width: 768px) {
    .form-grid {
        grid-template-columns: 1fr;
    }

    .checkbox-grid {
        grid-template-columns: 1fr;
    }

    .checkbox-list {
        grid-template-columns: 1fr;
    }
}
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

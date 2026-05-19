<?php
/**
 * Users Management - Edit User Profile (профиль автора)
 */

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/FileUploader.php';

$pageTitle = 'Редактировать пользователя';

$id = (int)($_GET['id'] ?? 0);
if ($id === 0) {
    header('Location: /admin/users/');
    exit;
}

$userObj = new User($db);
$user = $userObj->getById($id);
if (!$user) {
    header('Location: /admin/users/');
    exit;
}

$avatarDir = __DIR__ . '/../../uploads/avatars/';
$errors = [];
$successMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $updateData = [
        'full_name' => trim($_POST['full_name'] ?? ''),
        'organization' => trim($_POST['organization'] ?? ''),
        'author_bio' => trim($_POST['author_bio'] ?? ''),
        'social_vk' => trim($_POST['social_vk'] ?? ''),
        'social_telegram' => trim($_POST['social_telegram'] ?? ''),
    ];

    if ($updateData['full_name'] === '') {
        $errors[] = 'Имя обязательно';
    }
    foreach (['social_vk' => 'ВКонтакте', 'social_telegram' => 'Telegram'] as $f => $label) {
        if ($updateData[$f] !== '' && !filter_var($updateData[$f], FILTER_VALIDATE_URL)) {
            $errors[] = "Ссылка $label должна быть корректным URL";
        }
    }

    // Аватар: удаление или загрузка нового
    if (!empty($_POST['remove_avatar'])) {
        if (!empty($user['avatar_path'])) {
            (new FileUploader($avatarDir))->delete($user['avatar_path']);
        }
        $updateData['avatar_path'] = '';
    } elseif (isset($_FILES['avatar']) && $_FILES['avatar']['error'] !== UPLOAD_ERR_NO_FILE) {
        $uploader = new FileUploader(
            $avatarDir,
            ['image/jpeg', 'image/png', 'image/webp'],
            2 * 1024 * 1024,
            ['jpg', 'jpeg', 'png', 'webp']
        );
        $result = $uploader->upload($_FILES['avatar']);
        if ($result['success']) {
            if (!empty($user['avatar_path'])) {
                $uploader->delete($user['avatar_path']);
            }
            $updateData['avatar_path'] = $result['path'];
        } else {
            $errors[] = 'Аватар: ' . $result['error'];
        }
    }

    if (empty($errors)) {
        try {
            $userObj->update($id, $updateData);
            $successMessage = 'Профиль обновлён';
            $user = $userObj->getById($id);
        } catch (Exception $e) {
            $errors[] = 'Ошибка при сохранении: ' . $e->getMessage();
        }
    }
}

// Текущий аватар для превью
if (!empty($user['avatar_path'])) {
    $avatarUrl = '/uploads/avatars/' . ltrim($user['avatar_path'], '/');
} else {
    $avatarUrl = 'https://www.gravatar.com/avatar/' . md5(strtolower(trim($user['email'] ?? ''))) . '?d=mp&s=160';
}

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <div>
        <h1>Редактировать пользователя</h1>
        <p>ID: <?php echo $id; ?> | <?php echo htmlspecialchars($user['email']); ?></p>
    </div>
    <a href="/admin/users/" class="btn btn-secondary">← Назад</a>
</div>

<?php if ($successMessage): ?>
<div class="alert alert-success"><?php echo htmlspecialchars($successMessage); ?></div>
<?php endif; ?>

<?php if (!empty($errors)): ?>
<div class="alert alert-error">
    <strong>Ошибки:</strong>
    <ul style="margin:8px 0 0 20px;padding:0;">
        <?php foreach ($errors as $error): ?>
            <li><?php echo htmlspecialchars($error); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<form method="POST" action="" enctype="multipart/form-data" class="admin-form">
    <div class="content-card" style="margin-bottom:24px;">
        <h2 style="margin-bottom:24px;font-size:20px;">Профиль автора</h2>

        <div class="form-group" style="margin-bottom:20px;">
            <label class="form-label">Текущий аватар</label>
            <div style="display:flex;align-items:center;gap:16px;">
                <img src="<?php echo htmlspecialchars($avatarUrl); ?>" alt="" width="80" height="80" style="border-radius:50%;object-fit:cover;background:#eee;">
                <div>
                    <input type="file" name="avatar" accept="image/jpeg,image/png,image/webp">
                    <p class="form-help">JPG, PNG или WEBP, до 2 МБ. Если не загружен — используется Gravatar по email.</p>
                    <?php if (!empty($user['avatar_path'])): ?>
                        <label class="checkbox-label" style="font-size:14px;">
                            <input type="checkbox" name="remove_avatar" value="1">
                            <span>Удалить текущий аватар</span>
                        </label>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label for="full_name" class="form-label required">Имя (display_name)</label>
            <input type="text" id="full_name" name="full_name" class="form-input"
                   value="<?php echo htmlspecialchars($user['full_name'] ?? ''); ?>" required>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label for="organization" class="form-label">Организация</label>
            <input type="text" id="organization" name="organization" class="form-input"
                   value="<?php echo htmlspecialchars($user['organization'] ?? ''); ?>">
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label for="author_bio" class="form-label">Биография автора</label>
            <textarea id="author_bio" name="author_bio" class="form-textarea" rows="6"><?php echo htmlspecialchars($user['author_bio'] ?? ''); ?></textarea>
            <p class="form-help">Описание выводится на странице автора /avtor/<?php echo $id; ?>/</p>
        </div>

        <div class="form-group" style="margin-bottom:20px;">
            <label for="social_vk" class="form-label">Ссылка ВКонтакте</label>
            <input type="url" id="social_vk" name="social_vk" class="form-input"
                   value="<?php echo htmlspecialchars($user['social_vk'] ?? ''); ?>" placeholder="https://vk.com/...">
        </div>

        <div class="form-group">
            <label for="social_telegram" class="form-label">Ссылка Telegram</label>
            <input type="url" id="social_telegram" name="social_telegram" class="form-input"
                   value="<?php echo htmlspecialchars($user['social_telegram'] ?? ''); ?>" placeholder="https://t.me/...">
        </div>
    </div>

    <div class="form-actions">
        <button type="submit" class="btn btn-primary">💾 Сохранить</button>
        <a href="/admin/users/" class="btn btn-secondary">Отмена</a>
        <?php if ((int)($user['publications_count'] ?? 0) > 0): ?>
            <a href="/avtor/<?php echo $id; ?>/" class="btn btn-outline" target="_blank">👁️ Страница автора</a>
        <?php endif; ?>
    </div>
</form>

<style>
.admin-form { max-width: 760px; }
.form-label { display:block; font-weight:600; font-size:14px; margin-bottom:8px; color:var(--text-dark); }
.form-label.required::after { content:' *'; color:#ef4444; }
.form-input, .form-textarea {
    width:100%; padding:12px 16px; font-size:15px; border:2px solid #e5e7eb;
    border-radius:10px; font-family:inherit; transition:border-color .2s;
}
.form-input:focus, .form-textarea:focus { outline:none; border-color:var(--primary-purple); }
.form-textarea { resize:vertical; }
.form-help { margin-top:6px; font-size:13px; color:var(--text-medium); }
.checkbox-label { display:flex; align-items:center; gap:8px; cursor:pointer; margin-top:8px; }
.form-actions {
    display:flex; gap:12px; flex-wrap:wrap; margin-top:24px;
    padding:24px; background:#f9fafb; border-radius:12px;
}
.alert { padding:16px 20px; border-radius:12px; margin-bottom:24px; font-size:14px; }
.alert-success { background:#d1fae5; color:#065f46; border:1px solid #10b981; }
.alert-error { background:#fee2e2; color:#991b1b; border:1px solid #ef4444; }
.btn-outline { background:#fff; color:var(--primary-purple); border:2px solid var(--primary-purple); }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>

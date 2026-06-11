<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Импорт CSV в old_base_subscribers — для последующих пополнений базы.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/OldBaseSubscriber.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../includes/session.php';

$pageTitle = 'Импорт CSV (старая база)';

$report = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Admin::verifySession();
    $token = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($token)) {
        $error = 'Невалидный CSRF-токен';
    } elseif (empty($_FILES['csv']['tmp_name']) || $_FILES['csv']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Не удалось загрузить файл';
    } else {
        $source = trim($_POST['source'] ?? '') ?: ('upload_' . date('Y_m_d'));
        try {
            $sub = new OldBaseSubscriber($db);
            $report = $sub->importFromCsv($_FILES['csv']['tmp_name'], $source);
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

include __DIR__ . '/../includes/header.php';
$csrf = generateCSRFToken();
?>

<div class="page-header">
    <h1>📥 Импорт CSV</h1>
    <p class="page-subtitle">Формат: первая строка — заголовок, далее «Email,ФИО». Идемпотентно — повторный импорт обновит ФИО.</p>
</div>

<div class="content-card" style="margin-bottom:16px;display:flex;gap:8px;padding:16px;">
    <a href="/admin/old-base/index.php" class="btn btn-secondary btn-sm">Кампании</a>
    <a href="/admin/old-base/subscribers.php" class="btn btn-secondary btn-sm">Подписчики</a>
    <a href="/admin/old-base/import.php" class="btn btn-primary btn-sm">Импорт CSV</a>
</div>

<?php if ($error): ?>
    <div class="content-card" style="padding:16px;border-left:4px solid #ef4444;background:#fef2f2;margin-bottom:16px;">
        <strong>Ошибка:</strong> <?= htmlspecialchars($error) ?>
    </div>
<?php endif; ?>

<?php if ($report): ?>
    <div class="content-card" style="padding:16px;border-left:4px solid #10b981;background:#f0fdf4;margin-bottom:16px;">
        <strong>Импорт завершён.</strong>
        <ul style="margin:8px 0 0 20px;">
            <li>Строк в CSV: <strong><?= (int)$report['total'] ?></strong></li>
            <li>Валидных email: <?= (int)$report['valid'] ?></li>
            <li>Невалидных (отброшено): <?= (int)$report['invalid'] ?></li>
            <li>Новых записей: <?= (int)$report['inserted'] ?></li>
            <li>Обновлено: <?= (int)$report['updated'] ?></li>
            <li>Привязано к users: <?= (int)$report['linked_to_users'] ?></li>
            <li>Уже в unsubscribe: <?= (int)$report['already_unsubscribed'] ?></li>
        </ul>
    </div>
<?php endif; ?>

<div class="content-card" style="padding:24px;">
    <form method="POST" enctype="multipart/form-data">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
        <div style="margin-bottom:16px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">CSV-файл</label>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <p style="color:#666;font-size:12px;margin-top:4px;">Формат: <code>Email,ФИО</code> (с заголовком)</p>
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-weight:600;margin-bottom:6px;">Метка источника (source)</label>
            <input type="text" name="source" placeholder="upload_2026_05_15" style="padding:6px 10px;width:300px;">
            <p style="color:#666;font-size:12px;margin-top:4px;">Произвольная метка для трекинга, откуда пришли подписчики.</p>
        </div>
        <button type="submit" class="btn btn-primary">Загрузить</button>
    </form>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

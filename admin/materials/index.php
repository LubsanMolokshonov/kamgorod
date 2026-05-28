<?php
/**
 * Admin: модерация материалов ФОП.
 *
 * Список с фильтром по статусу. Действия:
 *   - publish — опубликовать (status='published', published_at=NOW)
 *   - reject  — отклонить (status='rejected', moderation_comment)
 *   - archive — архивировать (status='archived')
 *
 * Доступ — через стандартный admin .htaccess (как для остальных admin-страниц).
 */

session_start();
header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Material.php';
require_once __DIR__ . '/../../classes/MaterialType.php';

$materialObj = new Material($db);
$typeObj = new MaterialType($db);

// POST: действия модерации
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'publish') {
            $materialObj->publish($id);
        } elseif ($action === 'reject') {
            $materialObj->update($id, [
                'status' => 'rejected',
                'moderation_comment' => trim((string)($_POST['comment'] ?? '')),
            ]);
        } elseif ($action === 'archive') {
            $materialObj->update($id, ['status' => 'archived']);
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$filterStatus = $_GET['status'] ?? 'review';
$validStatuses = ['draft', 'review', 'published', 'rejected', 'archived'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'review';
}

$db2 = new Database($db);
$materials = $db2->query(
    "SELECT m.*, mt.name AS type_name, u.email AS author_email
     FROM materials m
     LEFT JOIN material_types mt ON m.material_type_id = mt.id
     LEFT JOIN users u ON m.user_id = u.id
     WHERE m.status = ?
     ORDER BY m.created_at DESC
     LIMIT 100",
    [$filterStatus]
);

$counts = [];
foreach ($validStatuses as $s) {
    $row = $db2->queryOne("SELECT COUNT(*) AS cnt FROM materials WHERE status = ?", [$s]);
    $counts[$s] = (int)($row['cnt'] ?? 0);
}

$statusLabels = [
    'draft' => 'Черновики',
    'review' => 'На модерации',
    'published' => 'Опубликовано',
    'rejected' => 'Отклонено',
    'archived' => 'В архиве',
];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Материалы ФОП — модерация · admin</title>
    <style>
        body { font-family: -apple-system, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 16px; color: #222; }
        h1 { margin: 0 0 16px; }
        .tabs { display: flex; gap: 8px; margin: 16px 0; flex-wrap: wrap; }
        .tab { padding: 8px 14px; background: #eee; border-radius: 8px; text-decoration: none; color: #222; font-size: 14px; }
        .tab.active { background: #222; color: #fff; }
        .tab .count { font-size: 12px; opacity: 0.7; margin-left: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; font-weight: 600; }
        .actions form { display: inline; margin-right: 4px; }
        .actions button { padding: 6px 12px; border: none; border-radius: 6px; cursor: pointer; font-size: 13px; }
        .btn-publish { background: #2c8a2c; color: #fff; }
        .btn-reject { background: #c33; color: #fff; }
        .btn-archive { background: #888; color: #fff; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #eef; color: #336; }
    </style>
</head>
<body>
    <p><a href="/admin/">← Админка</a></p>
    <h1>Материалы ФОП — модерация</h1>

    <div class="tabs">
        <?php foreach ($validStatuses as $s): ?>
            <a class="tab <?= $filterStatus === $s ? 'active' : '' ?>" href="?status=<?= $s ?>">
                <?= htmlspecialchars($statusLabels[$s], ENT_QUOTES, 'UTF-8') ?>
                <span class="count"><?= $counts[$s] ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($materials)): ?>
        <p style="color:#888;">В этом статусе нет материалов.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:50px;">ID</th>
                    <th>Название</th>
                    <th style="width:140px;">Тип</th>
                    <th style="width:180px;">Автор</th>
                    <th style="width:140px;">Создан</th>
                    <th style="width:280px;">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($materials as $m): ?>
                    <tr>
                        <td><?= (int)$m['id'] ?></td>
                        <td>
                            <a href="/material/<?= htmlspecialchars($m['slug'], ENT_QUOTES, 'UTF-8') ?>/" target="_blank">
                                <?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <?php if (!empty($m['is_generated'])): ?>
                                <span class="badge">ИИ</span>
                            <?php endif; ?>
                            <?php if (!empty($m['moderation_comment']) && $m['status'] === 'rejected'): ?>
                                <div style="font-size:12px; color:#c33; margin-top:4px;">
                                    <?= htmlspecialchars($m['moderation_comment'], ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($m['type_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($m['author_email'] ?? '— редакция —', ENT_QUOTES, 'UTF-8') ?></td>
                        <td style="color:#888; font-size:12px;">
                            <?= htmlspecialchars(date('d.m.Y H:i', strtotime($m['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </td>
                        <td class="actions">
                            <?php if ($m['status'] !== 'published'): ?>
                                <form method="post" onsubmit="return confirm('Опубликовать?');">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="action" value="publish">
                                    <button class="btn-publish" type="submit">Опубликовать</button>
                                </form>
                            <?php endif; ?>
                            <?php if (!in_array($m['status'], ['rejected', 'archived'], true)): ?>
                                <form method="post" onsubmit="this.querySelector('[name=comment]').value = prompt('Причина отклонения:') || ''; return this.querySelector('[name=comment]').value !== '';">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="comment" value="">
                                    <button class="btn-reject" type="submit">Отклонить</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($m['status'] !== 'archived'): ?>
                                <form method="post" onsubmit="return confirm('В архив?');">
                                    <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
                                    <input type="hidden" name="action" value="archive">
                                    <button class="btn-archive" type="submit">В архив</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>

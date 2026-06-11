<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Admin: модерация отзывов на продукты.
 *
 * Список с фильтром по статусу. Действия:
 *   - approve — одобрить (status='approved') → пересчёт review_stats
 *   - reject  — отклонить (status='rejected') → пересчёт review_stats
 *
 * Доступ — через серверную защиту /admin (как у остальных admin-страниц).
 * Очередь модерации = status='pending' (всё, что YandexGPT не пропустил автоматически).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/Review.php';
require_once __DIR__ . '/../../includes/session.php';

// Доступ только для авторизованного админа (session_start внутри verifySession).
Admin::verifySession();
header('Content-Type: text/html; charset=UTF-8');

$reviewObj = new Review($db);

// POST: действия модерации (с CSRF — действия влияют на публичный рейтинг).
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        http_response_code(403);
        echo 'Недействительный токен безопасности';
        exit;
    }
    $action = $_POST['action'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    if ($id > 0) {
        if ($action === 'approve') {
            $reviewObj->approve($id);
        } elseif ($action === 'reject') {
            $reviewObj->reject($id);
        }
    }
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

$csrf = generateCSRFToken();

$filterStatus = $_GET['status'] ?? 'pending';
$validStatuses = ['pending', 'approved', 'rejected'];
if (!in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = 'pending';
}

$reviews = $reviewObj->getByStatus($filterStatus, 200);

$db2 = new Database($db);
$counts = array_fill_keys($validStatuses, 0);
foreach ($db2->query("SELECT status, COUNT(*) AS cnt FROM reviews GROUP BY status") as $row) {
    if (isset($counts[$row['status']])) {
        $counts[$row['status']] = (int)$row['cnt'];
    }
}

$statusLabels = [
    'pending' => 'На модерации',
    'approved' => 'Одобрено',
    'rejected' => 'Отклонено',
];

// Метки типов продуктов + базовый префикс публичного URL по slug-зависимым разделам.
$typeLabels = [
    'competition' => 'Конкурс',
    'olympiad' => 'Олимпиада',
    'webinar' => 'Вебинар',
    'course' => 'Курс',
    'publication' => 'Публикация',
    'material' => 'Материал',
];

/** 5 звёзд по числу заполненных. */
function adminStars(int $n): string {
    $out = '';
    for ($i = 1; $i <= 5; $i++) {
        $out .= '<span style="color:' . ($i <= $n ? '#f59e0b' : '#d1d5db') . '">★</span>';
    }
    return $out;
}

function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Отзывы — модерация · admin</title>
    <style>
        body { font-family: -apple-system, sans-serif; max-width: 1200px; margin: 20px auto; padding: 0 16px; color: #222; }
        h1 { margin: 0 0 16px; }
        .back { color: #2563eb; text-decoration: none; font-size: 14px; }
        .tabs { display: flex; gap: 8px; margin: 16px 0; flex-wrap: wrap; }
        .tab { padding: 8px 14px; background: #eee; border-radius: 8px; text-decoration: none; color: #222; font-size: 14px; }
        .tab.active { background: #222; color: #fff; }
        .tab .count { font-size: 12px; opacity: 0.7; margin-left: 4px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th, td { padding: 10px 8px; border-bottom: 1px solid #eee; text-align: left; vertical-align: top; }
        th { background: #f5f5f5; font-weight: 600; }
        .product { font-weight: 600; white-space: nowrap; }
        .product small { display: block; font-weight: 400; color: #888; }
        .rtext { max-width: 420px; white-space: pre-wrap; }
        .reason { color: #999; font-size: 12px; margin-top: 4px; }
        .actions { white-space: nowrap; }
        .btn { border: none; border-radius: 6px; padding: 6px 12px; font-size: 13px; cursor: pointer; color: #fff; }
        .btn-approve { background: #059669; }
        .btn-reject { background: #dc2626; }
        .empty { color: #888; padding: 40px 0; text-align: center; }
        .meta { color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <a href="/admin/" class="back">← В админку</a>
    <h1>Отзывы — модерация</h1>

    <div class="tabs">
        <?php foreach ($statusLabels as $s => $label): ?>
            <a class="tab <?= $s === $filterStatus ? 'active' : '' ?>" href="?status=<?= $s ?>">
                <?= $label ?><span class="count"><?= $counts[$s] ?? 0 ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <?php if (empty($reviews)): ?>
        <p class="empty">Отзывов в этом статусе нет.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Продукт</th>
                    <th>Автор</th>
                    <th>Оценка</th>
                    <th>Отзыв</th>
                    <th>Дата</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $r): ?>
                    <tr>
                        <td class="product">
                            <?= h($typeLabels[$r['entity_type']] ?? $r['entity_type']) ?>
                            <small>#<?= (int)$r['entity_id'] ?></small>
                        </td>
                        <td>
                            <?= h($r['author_name']) ?>
                            <?php if (!empty($r['user_id'])): ?><div class="meta">user #<?= (int)$r['user_id'] ?></div><?php endif; ?>
                            <?php if (!empty($r['ip_address'])): ?><div class="meta"><?= h($r['ip_address']) ?></div><?php endif; ?>
                        </td>
                        <td><?= adminStars((int)$r['rating']) ?></td>
                        <td class="rtext">
                            <?= nl2br(h($r['review_text'])) ?: '<span class="meta">— без текста —</span>' ?>
                            <?php if (!empty($r['moderation_reason'])): ?>
                                <div class="reason">ИИ: <?= h($r['moderation_reason']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="meta"><?= h(date('d.m.Y H:i', strtotime($r['created_at']))) ?></td>
                        <td class="actions">
                            <?php if ($r['status'] !== 'approved'): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="approve">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-approve" type="submit">Одобрить</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($r['status'] !== 'rejected'): ?>
                                <form method="post" style="display:inline">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrf) ?>">
                                    <input type="hidden" name="action" value="reject">
                                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                    <button class="btn btn-reject" type="submit">Отклонить</button>
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

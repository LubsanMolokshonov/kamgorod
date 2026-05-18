<?php
/**
 * Подписчики старой базы — список с пагинацией и фильтрами.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/OldBaseSubscriber.php';

$pageTitle = 'Подписчики старой базы';

$sub = new OldBaseSubscriber($db);

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$filters = [
    'q'      => trim($_GET['q'] ?? ''),
    'status' => $_GET['status'] ?? '',
];

$result = $sub->paginate($filters, $page, $perPage);
$counts = $sub->statusCounts();

include __DIR__ . '/../includes/header.php';

$statusLabels = [
    'active'        => 'Активен',
    'unsubscribed'  => 'Отписался',
    'bounced'       => 'Bounce',
    'complained'    => 'Жалоба',
    'suppressed'    => 'Подавлен',
];
?>

<div class="page-header">
    <h1>👥 Подписчики старой базы</h1>
    <p class="page-subtitle">Всего: <strong><?= (int)$counts['total'] ?></strong> · Активных: <strong><?= (int)$counts['active'] ?></strong> · Отписались: <?= (int)$counts['unsubscribed'] ?> · Bounce: <?= (int)$counts['bounced'] ?></p>
</div>

<div class="content-card" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;padding:16px;">
    <a href="/admin/old-base/index.php" class="btn btn-secondary btn-sm">Кампании</a>
    <a href="/admin/old-base/subscribers.php" class="btn btn-primary btn-sm">Подписчики</a>
    <a href="/admin/old-base/import.php" class="btn btn-secondary btn-sm">Импорт CSV</a>
</div>

<div class="content-card" style="padding:16px;margin-bottom:16px;">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:end;">
        <div>
            <label style="display:block;font-size:12px;color:#666;">Поиск (email/ФИО)</label>
            <input type="text" name="q" value="<?= htmlspecialchars($filters['q']) ?>" style="padding:6px 10px;">
        </div>
        <div>
            <label style="display:block;font-size:12px;color:#666;">Статус</label>
            <select name="status" style="padding:6px 10px;">
                <option value="">— все —</option>
                <?php foreach ($statusLabels as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $filters['status'] === $k ? 'selected' : '' ?>><?= $v ?> (<?= (int)$counts[$k] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">Применить</button>
        <?php if ($filters['q'] || $filters['status']): ?>
            <a href="/admin/old-base/subscribers.php" class="btn btn-secondary btn-sm">Сброс</a>
        <?php endif; ?>
    </form>
</div>

<div class="content-card">
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Email</th>
                <th>ФИО</th>
                <th>Статус</th>
                <th>User</th>
                <th>Отпр.</th>
                <th>Откр.</th>
                <th>Кликов</th>
                <th>Конв.</th>
                <th>Послед. отправка</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($result['rows'])): ?>
            <tr><td colspan="10" style="text-align:center;color:#888;padding:24px;">Подписчики не найдены</td></tr>
        <?php else: ?>
            <?php foreach ($result['rows'] as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td><?= htmlspecialchars($r['full_name'] ?? '—') ?></td>
                    <td><span class="badge badge-secondary"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
                    <td><?= $r['user_id'] ? '<a href="/admin/users/?id=' . (int)$r['user_id'] . '">#' . (int)$r['user_id'] . '</a>' : '—' ?></td>
                    <td><?= (int)$r['total_sent'] ?></td>
                    <td><?= (int)$r['total_opened'] ?></td>
                    <td><?= (int)$r['total_clicked'] ?></td>
                    <td><?= (int)$r['total_converted'] ?></td>
                    <td><?= $r['last_sent_at'] ? date('d.m.Y H:i', strtotime($r['last_sent_at'])) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if ($result['total_pages'] > 1): ?>
        <?php
        $qs = $_GET; unset($qs['page']);
        $base = '?' . http_build_query($qs);
        $base = $base === '?' ? '?' : $base . '&';
        ?>
        <div style="padding:16px;display:flex;gap:8px;justify-content:center;">
            <?php if ($page > 1): ?>
                <a href="<?= $base ?>page=<?= $page - 1 ?>" class="btn btn-secondary btn-sm">&larr;</a>
            <?php endif; ?>
            <span style="padding:6px 14px;">Стр. <?= $page ?> из <?= (int)$result['total_pages'] ?> (всего <?= (int)$result['total'] ?>)</span>
            <?php if ($page < $result['total_pages']): ?>
                <a href="<?= $base ?>page=<?= $page + 1 ?>" class="btn btn-secondary btn-sm">&rarr;</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

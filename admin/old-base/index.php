<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Email-рассылки по старой базе — список кампаний.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../classes/OldBaseSubscriber.php';

$pageTitle = 'Рассылки по старой базе';

$camp = new OldBaseCampaign($db);
$sub  = new OldBaseSubscriber($db);

$campaigns = $camp->listAll();
$subStats = $sub->statusCounts();

include __DIR__ . '/../includes/header.php';

$statusLabels = [
    'draft' => 'Черновик',
    'scheduled' => 'Запланирована',
    'running' => 'Идёт',
    'paused' => 'Пауза',
    'completed' => 'Завершена',
    'cancelled' => 'Отменена',
];
$statusClass = [
    'draft' => 'badge-secondary',
    'scheduled' => 'badge-info',
    'running' => 'badge-success',
    'paused' => 'badge-warning',
    'completed' => 'badge-primary',
    'cancelled' => 'badge-secondary',
];
?>

<div class="page-header">
    <h1>📨 Рассылки по старой базе</h1>
    <p class="page-subtitle">Импортированная база из CSV (~31k подписчиков). Прогрев, кампании, аналитика.</p>
</div>

<div class="content-card" style="margin-bottom:16px;display:flex;gap:8px;flex-wrap:wrap;padding:16px;">
    <a href="/admin/old-base/index.php" class="btn btn-primary btn-sm">Кампании</a>
    <a href="/admin/old-base/subscribers.php" class="btn btn-secondary btn-sm">Подписчики (<?= (int)$subStats['total'] ?>)</a>
    <a href="/admin/old-base/import.php" class="btn btn-secondary btn-sm">Импорт CSV</a>
    <a href="/admin/old-base/campaign-create.php" class="btn btn-success btn-sm" style="margin-left:auto;">+ Новая кампания</a>
</div>

<div class="content-card">
    <?php if (empty($campaigns)): ?>
        <div style="padding:32px;text-align:center;color:#666;">
            Кампаний пока нет. <a href="/admin/old-base/campaign-create.php">Создайте первую</a>.
        </div>
    <?php else: ?>
    <table class="admin-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Название / код</th>
                <th>Статус</th>
                <th>Получателей</th>
                <th>Прогресс</th>
                <th>Откр.</th>
                <th>Клики</th>
                <th>Заявок</th>
                <th>Оплат</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($campaigns as $c):
            $stats = $camp->getStats((int)$c['id']);
            $progressPct = $stats['total_planned'] > 0
                ? round(100 * $stats['total_sent'] / $stats['total_planned'])
                : 0;
            $openRate = $stats['total_sent'] > 0
                ? round(100 * $stats['unique_opens'] / $stats['total_sent'], 1)
                : 0;
            $clickRate = $stats['total_sent'] > 0
                ? round(100 * $stats['unique_clicks'] / $stats['total_sent'], 1)
                : 0;
        ?>
            <tr>
                <td><?= (int)$c['id'] ?></td>
                <td>
                    <a href="/admin/old-base/campaign-view.php?id=<?= (int)$c['id'] ?>">
                        <strong><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </a>
                    <div style="font-size:11px;color:#888;font-family:monospace;"><?= htmlspecialchars($c['code']) ?></div>
                </td>
                <td><span class="badge <?= $statusClass[$c['status']] ?? '' ?>"><?= $statusLabels[$c['status']] ?? $c['status'] ?></span></td>
                <td><?= (int)$c['recipient_count'] ?></td>
                <td>
                    <?= $stats['total_sent'] ?> / <?= $stats['total_planned'] ?>
                    <div style="background:#eee;height:4px;border-radius:2px;margin-top:2px;">
                        <div style="background:#3b82f6;height:100%;width:<?= $progressPct ?>%;border-radius:2px;"></div>
                    </div>
                </td>
                <td><?= $stats['unique_opens'] ?> (<?= $openRate ?>%)</td>
                <td><?= $stats['unique_clicks'] ?> (<?= $clickRate ?>%)</td>
                <td><?= $stats['applications'] ?></td>
                <td><strong><?= $stats['payments'] ?></strong></td>
                <td>
                    <a href="/admin/old-base/campaign-view.php?id=<?= (int)$c['id'] ?>" class="btn btn-secondary btn-sm">Открыть</a>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

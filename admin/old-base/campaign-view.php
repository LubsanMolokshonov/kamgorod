<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Детальная страница кампании: статистика + recipients + действия.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../includes/session.php';

$current = Admin::verifySession();

$id = (int)($_GET['id'] ?? 0);
$campaign = new OldBaseCampaign($db);
$c = $campaign->find($id);
if (!$c) {
    http_response_code(404);
    die('Кампания не найдена');
}

$pageTitle = 'Рассылка: ' . $c['name'];
$additionalJS = ['/admin/old-base/campaign-view.js'];

$stats   = $campaign->getStats($id);
$daily   = $campaign->dailyBreakdown($id);
$recPage = max(1, (int)($_GET['page'] ?? 1));
$rec     = $campaign->recipients($id, ['status' => $_GET['status'] ?? '', 'q' => $_GET['q'] ?? ''], $recPage, 50);

$openRate  = $stats['total_sent'] > 0 ? round(100 * $stats['unique_opens']  / $stats['total_sent'], 1) : 0;
$clickRate = $stats['total_sent'] > 0 ? round(100 * $stats['unique_clicks'] / $stats['total_sent'], 1) : 0;

$canPause  = $c['status'] === 'running';
$canResume = in_array($c['status'], ['paused', 'draft', 'scheduled'], true);
$canCancel = in_array($c['status'], ['running','paused','scheduled','draft'], true);

$csrf = generateCSRFToken();

include __DIR__ . '/../includes/header.php';

$statusLabels = [
    'draft' => 'Черновик', 'scheduled' => 'Запланирована', 'running' => 'Идёт',
    'paused' => 'Пауза', 'completed' => 'Завершена', 'cancelled' => 'Отменена',
];
?>

<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;">
    <div>
        <h1>📬 <?= htmlspecialchars($c['name']) ?></h1>
        <p class="page-subtitle">
            Код: <code><?= htmlspecialchars($c['code']) ?></code> ·
            Статус: <strong><?= $statusLabels[$c['status']] ?? $c['status'] ?></strong> ·
            Старт: <?= htmlspecialchars($c['start_date']) ?> · Окно: <?= substr($c['send_window_start'], 0, 5) ?>–<?= substr($c['send_window_end'], 0, 5) ?> · TZ: <?= htmlspecialchars($c['timezone']) ?>
        </p>
    </div>
    <div>
        <a href="/admin/old-base/index.php" class="btn btn-secondary btn-sm">К списку</a>
    </div>
</div>

<!-- Действия -->
<div class="content-card" style="padding:16px;margin-bottom:16px;display:flex;flex-wrap:wrap;gap:8px;">
    <?php if ($canResume): ?>
        <button class="btn btn-success btn-sm" data-action="resume">▶ Запустить</button>
    <?php endif; ?>
    <?php if ($canPause): ?>
        <button class="btn btn-warning btn-sm" data-action="pause">⏸ Пауза</button>
    <?php endif; ?>
    <?php if ($canCancel): ?>
        <button class="btn btn-danger btn-sm" data-action="cancel">⏹ Отменить</button>
    <?php endif; ?>
    <a href="/admin/old-base/campaign-create.php?id=<?= $id ?>" class="btn btn-secondary btn-sm">✏️ Редактировать</a>

    <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
        <input type="email" id="testEmail" placeholder="test@example.com" style="padding:6px 10px;">
        <button class="btn btn-secondary btn-sm" data-action="test">📧 Тест-отправка</button>
        <button class="btn btn-primary btn-sm" data-action="clone-winners">🏆 Клон на winners</button>
        <button class="btn btn-primary btn-sm" data-action="clone-rest">🌐 Клон на остальную базу</button>
    </div>
</div>

<!-- KPI -->
<div class="content-card" style="padding:16px;margin-bottom:16px;">
    <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:12px;">
        <?php
        $kpis = [
            ['План',     $stats['total_planned']],
            ['Отправлено', $stats['total_sent']],
            ['Доставлено', $stats['delivered']],
            ['Уник. откр.', $stats['unique_opens'] . ' (' . $openRate . '%)'],
            ['Уник. клики', $stats['unique_clicks'] . ' (' . $clickRate . '%)'],
            ['Заявки', $stats['applications']],
            ['Оплат',  $stats['payments']],
        ];
        foreach ($kpis as [$label, $value]):
        ?>
            <div style="background:#f9fafb;padding:12px;border-radius:6px;text-align:center;">
                <div style="font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.5px;"><?= htmlspecialchars($label) ?></div>
                <div style="font-size:22px;font-weight:700;margin-top:4px;"><?= htmlspecialchars($value) ?></div>
            </div>
        <?php endforeach; ?>
    </div>
    <?php if ($stats['revenue'] > 0): ?>
        <div style="margin-top:12px;text-align:right;color:#10b981;font-weight:600;">
            Выручка: <?= number_format($stats['revenue'], 0, ',', ' ') ?> ₽
        </div>
    <?php endif; ?>
</div>

<!-- График по дням -->
<?php if (!empty($daily)): ?>
<div class="content-card" style="padding:16px;margin-bottom:16px;">
    <h3>По дням</h3>
    <table class="admin-table">
        <thead><tr><th>День</th><th>План</th><th>Отправлено</th><th>Доставлено</th><th>Открыто</th><th>Кликов</th></tr></thead>
        <tbody>
        <?php foreach ($daily as $d): ?>
            <tr>
                <td><?= htmlspecialchars($d['day']) ?></td>
                <td><?= (int)$d['planned'] ?></td>
                <td><?= (int)$d['sent'] ?></td>
                <td><?= (int)$d['delivered'] ?></td>
                <td><?= (int)$d['opened'] ?></td>
                <td><?= (int)$d['clicked'] ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- Получатели -->
<div class="content-card" style="padding:16px;">
    <h3>Получатели (<?= (int)$rec['total'] ?>)</h3>
    <form method="GET" style="margin-bottom:12px;display:flex;gap:8px;">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="text" name="q" placeholder="Email" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" style="padding:6px 10px;">
        <select name="status" style="padding:6px 10px;">
            <option value="">— все —</option>
            <?php foreach (['pending','sent','failed','skipped','bounced','unsubscribed'] as $s): ?>
                <option value="<?= $s ?>" <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">Фильтр</button>
    </form>
    <table class="admin-table">
        <thead>
            <tr>
                <th>ID</th><th>Email</th><th>Статус</th><th>Запланировано</th>
                <th>Отправлено</th><th>Откр.</th><th>Кликов</th><th>Заказ</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rec['rows'] as $r): ?>
            <tr>
                <td><?= (int)$r['id'] ?></td>
                <td><?= htmlspecialchars($r['email']) ?></td>
                <td><?= htmlspecialchars($r['status']) ?></td>
                <td><?= htmlspecialchars($r['scheduled_at']) ?></td>
                <td><?= $r['sent_at'] ? htmlspecialchars($r['sent_at']) : '—' ?></td>
                <td><?= (int)($r['opens_count'] ?? 0) ?></td>
                <td><?= (int)($r['clicks_count'] ?? 0) ?></td>
                <td><?= $r['order_id'] ? '#' . (int)$r['order_id'] : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <?php if ($rec['total_pages'] > 1): ?>
        <?php $qs = $_GET; unset($qs['page']); $base = '?' . http_build_query($qs) . '&'; ?>
        <div style="padding:16px;display:flex;gap:8px;justify-content:center;">
            <?php if ($recPage > 1): ?><a href="<?= $base ?>page=<?= $recPage - 1 ?>" class="btn btn-secondary btn-sm">&larr;</a><?php endif; ?>
            <span style="padding:6px 14px;">Стр. <?= $recPage ?> из <?= $rec['total_pages'] ?></span>
            <?php if ($recPage < $rec['total_pages']): ?><a href="<?= $base ?>page=<?= $recPage + 1 ?>" class="btn btn-secondary btn-sm">&rarr;</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
window._campaignId = <?= $id ?>;
window._csrfToken = <?= json_encode($csrf) ?>;
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Админ: переписка в мессенджере «Макс» (ChatPush) — список диалогов по телефонам.
 * Источник данных — лента max_messages (исходящие уведомления, входящие ответы,
 * авто-ответы ИИ-менеджера, ручные ответы поддержки).
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Переписка «Макс»';

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

// Фильтры (белые списки).
$filter = $_GET['filter'] ?? '';
$validFilters = ['unanswered', 'has_reply', 'has_alert', 'failed'];
if ($filter && !in_array($filter, $validFilters, true)) {
    $filter = '';
}

// HAVING-условия по агрегатам диалога.
$having = [];
if ($filter === 'has_reply')  $having[] = 'inbound_cnt > 0';
if ($filter === 'has_alert')  $having[] = 'alert_cnt > 0';
if ($filter === 'failed')     $having[] = 'failed_cnt > 0';
if ($filter === 'unanswered') $having[] = "last_dir = 'in'";
$havingClause = empty($having) ? '' : 'HAVING ' . implode(' AND ', $having);

// last_dir — направление последнего сообщения диалога. Коррелированный подзапрос
// (а не GROUP_CONCAT/SUBSTRING_INDEX) не зависит от group_concat_max_len и не врёт на длинных тредах.
$baseSelect = "
    SELECT phone,
           MAX(user_id) AS user_id,
           MAX(created_at) AS last_at,
           COUNT(*) AS total_msgs,
           SUM(direction = 'in') AS inbound_cnt,
           SUM(alert_id IS NOT NULL) AS alert_cnt,
           SUM(`status` = 'failed') AS failed_cnt,
           (SELECT mm.direction FROM max_messages mm WHERE mm.phone = m.phone ORDER BY mm.id DESC LIMIT 1) AS last_dir
    FROM max_messages m
    GROUP BY phone
    {$havingClause}
";

// Всего диалогов (после HAVING).
$countStmt = $db->query("SELECT COUNT(*) FROM ({$baseSelect}) t");
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$listStmt = $db->prepare("{$baseSelect} ORDER BY last_at DESC LIMIT {$perPage} OFFSET {$offset}");
$listStmt->execute();
$rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

// Подтягиваем пользователей (имя/email) одним запросом.
$userIds = array_values(array_filter(array_map(static fn($r) => (int)$r['user_id'], $rows)));
$usersById = [];
if (!empty($userIds)) {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $uStmt = $db->prepare("SELECT id, full_name, email FROM users WHERE id IN ($ph)");
    $uStmt->execute($userIds);
    foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
        $usersById[(int)$u['id']] = $u;
    }
}

// Последнее сообщение каждого диалога (для превью).
$lastByPhone = [];
if (!empty($rows)) {
    $phones = array_map(static fn($r) => $r['phone'], $rows);
    $ph = implode(',', array_fill(0, count($phones), '?'));
    $lStmt = $db->prepare(
        "SELECT m.phone, m.author, m.direction, m.`text`, m.`status`, m.created_at
         FROM max_messages m
         JOIN (SELECT phone, MAX(id) AS max_id FROM max_messages WHERE phone IN ($ph) GROUP BY phone) lm
           ON lm.max_id = m.id"
    );
    $lStmt->execute($phones);
    foreach ($lStmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $lastByPhone[$m['phone']] = $m;
    }
}

$authorNames = ['system' => 'Уведомление', 'ai' => 'ИИ-менеджер', 'admin' => 'Поддержка', 'user' => 'Пользователь'];
$statusNames = ['sent' => 'отправлено', 'failed' => 'ошибка', 'pending' => 'в очереди'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Переписка «Макс»</h1>
    <p>Диалогов: <?php echo number_format($total, 0, ',', ' '); ?></p>
</div>

<?php
$buildLink = static function (?string $f) {
    return $f ? ('?' . http_build_query(['filter' => $f])) : '?';
};
$filters = [
    ''           => 'Все',
    'unanswered' => 'Ждут ответа',
    'has_reply'  => 'С ответами польз.',
    'has_alert'  => 'С алертами',
    'failed'     => 'Ошибки отправки',
];
?>
<div style="margin-bottom: 16px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
    <?php foreach ($filters as $key => $name): ?>
        <a href="<?php echo htmlspecialchars($buildLink($key ?: null)); ?>"
           class="btn <?php echo $filter === $key ? 'btn-primary' : 'btn-secondary'; ?> btn-sm">
            <?php echo $name; ?>
        </a>
    <?php endforeach; ?>
</div>

<div class="content-card">
    <div class="card-body" style="padding: 0;">
        <?php if (empty($rows)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">💬</div>
                <h3>Диалогов нет</h3>
                <p>Здесь появятся уведомления и ответы пользователей из мессенджера «Макс»</p>
            </div>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Телефон</th>
                        <th>Последнее сообщение</th>
                        <th>Сообщений</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r):
                        $uid = (int)$r['user_id'];
                        $user = $usersById[$uid] ?? null;
                        $last = $lastByPhone[$r['phone']] ?? null;
                        $inbound = (int)$r['inbound_cnt'];
                        $alerts  = (int)$r['alert_cnt'];
                        $failed  = (int)$r['failed_cnt'];
                        $awaiting = ($r['last_dir'] === 'in');
                    ?>
                        <tr>
                            <td>
                                <?php if ($user): ?>
                                    <strong><?php echo htmlspecialchars($user['full_name'] ?: '—'); ?></strong><br>
                                    <span style="font-size: 12px; color: #6B7280;"><?php echo htmlspecialchars($user['email'] ?: ''); ?></span>
                                <?php else: ?>
                                    <span style="color: #9CA3AF;">не сопоставлен</span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space: nowrap;"><?php echo htmlspecialchars($r['phone']); ?></td>
                            <td style="max-width: 320px;">
                                <?php if ($last): ?>
                                    <span style="font-size: 11px; color: #6B7280;">
                                        <?php echo htmlspecialchars($authorNames[$last['author']] ?? $last['author']); ?>
                                        · <?php echo date('d.m H:i', strtotime($last['created_at'])); ?>
                                    </span><br>
                                    <span style="font-size: 13px;">
                                        <?php echo htmlspecialchars(mb_substr((string)$last['text'], 0, 90) . (mb_strlen((string)$last['text']) > 90 ? '…' : '')); ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php echo (int)$r['total_msgs']; ?>
                                <?php if ($inbound > 0): ?>
                                    <span class="badge badge-blue" title="Входящих от пользователя"><?php echo $inbound; ?> вх.</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($awaiting): ?><span class="badge badge-warning">ждёт ответа</span><?php endif; ?>
                                <?php if ($alerts > 0): ?><span class="badge badge-purple">алерт</span><?php endif; ?>
                                <?php if ($failed > 0): ?><span class="badge badge-danger">ошибка</span><?php endif; ?>
                                <?php if (!$awaiting && $alerts === 0 && $failed === 0): ?><span class="badge badge-success">ок</span><?php endif; ?>
                            </td>
                            <td>
                                <a href="/admin/max/view.php?phone=<?php echo urlencode($r['phone']); ?>" class="btn btn-primary btn-sm">Открыть</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($totalPages > 1): ?>
                <?php
                $buildQs = static function ($p) use ($filter) {
                    $qs = [];
                    if ($filter) $qs['filter'] = $filter;
                    $qs['page'] = $p;
                    return http_build_query($qs);
                };
                ?>
                <div style="padding: 16px 24px; display: flex; gap: 8px; justify-content: center;">
                    <?php if ($page > 1): ?>
                        <a href="?<?php echo $buildQs($page - 1); ?>" class="btn btn-secondary btn-sm">&larr;</a>
                    <?php endif; ?>
                    <span style="padding: 6px 14px; font-size: 13px;">Стр. <?php echo $page; ?> из <?php echo $totalPages; ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?php echo $buildQs($page + 1); ?>" class="btn btn-secondary btn-sm">&rarr;</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

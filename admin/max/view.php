<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Админ: тред переписки «Макс» по телефону + ручной ответ поддержки.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/ChatpushClient.php';
require_once __DIR__ . '/../../includes/session.php';

$phoneRaw = (string)($_GET['phone'] ?? '');
$phone = ChatpushClient::normalizePhone($phoneRaw);
if ($phone === null) {
    header('Location: /admin/max/');
    exit;
}

$stmt = $db->prepare(
    "SELECT id, user_id, direction, author, `text`, `status`, http_code, error,
            order_id, alert_id, sent_by_admin_id, created_at
     FROM max_messages WHERE phone = ? ORDER BY created_at ASC, id ASC"
);
$stmt->execute([$phone]);
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($messages)) {
    header('Location: /admin/max/');
    exit;
}

// Пользователь (если сопоставлен).
$user = null;
$userId = 0;
foreach ($messages as $m) {
    if (!empty($m['user_id'])) { $userId = (int)$m['user_id']; break; }
}
if ($userId > 0) {
    $uStmt = $db->prepare('SELECT id, full_name, email, phone FROM users WHERE id = ? LIMIT 1');
    $uStmt->execute([$userId]);
    $user = $uStmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

$csrfToken = generateCSRFToken();
$pageTitle = 'Переписка «Макс» · ' . $phone;

$authorMeta = [
    'system' => ['label' => '🔔 Уведомление', 'bg' => '#F3F4F6', 'border' => '#9CA3AF', 'align' => 'left'],
    'ai'     => ['label' => '🤖 ИИ-менеджер', 'bg' => '#EEF4FF', 'border' => '#2E5BFF', 'align' => 'left'],
    'admin'  => ['label' => '👤 Поддержка',   'bg' => '#ECFDF5', 'border' => '#16A34A', 'align' => 'left'],
    'user'   => ['label' => '💬 Пользователь', 'bg' => '#FFFFFF', 'border' => '#E5E7EB', 'align' => 'right'],
];
$statusNames = ['sent' => 'отправлено', 'failed' => 'ошибка', 'pending' => 'в очереди'];

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <a href="/admin/max/" style="font-size: 13px; color: #6B7280; text-decoration: none;">← Все диалоги</a>
    <h1>Переписка «Макс»</h1>
    <p>
        Телефон: <strong><?php echo htmlspecialchars($phone); ?></strong>
        <?php if ($user): ?>
            · <?php echo htmlspecialchars($user['full_name'] ?: '—'); ?>
            (<a href="mailto:<?php echo htmlspecialchars($user['email']); ?>"><?php echo htmlspecialchars($user['email']); ?></a>)
            · <a href="/admin/users/edit.php?id=<?php echo (int)$user['id']; ?>">карточка</a>
        <?php else: ?>
            · <span style="color: #9CA3AF;">пользователь не сопоставлен</span>
        <?php endif; ?>
    </p>
</div>

<div style="display: grid; grid-template-columns: 1fr; gap: 20px; max-width: 820px;">
    <div class="content-card">
        <div class="card-body">
            <div style="max-height: 600px; overflow-y: auto; padding: 4px;">
                <?php foreach ($messages as $m):
                    $meta = $authorMeta[$m['author']] ?? $authorMeta['system'];
                    $isRight = $meta['align'] === 'right';
                ?>
                    <div style="margin-bottom: 14px; <?php echo $isRight ? 'margin-left: 60px;' : 'margin-right: 60px;'; ?>">
                        <div style="padding: 12px 16px; border-radius: 12px; background: <?php echo $meta['bg']; ?>; border: 1px solid <?php echo $meta['border']; ?>;">
                            <div style="font-size: 11px; color: #6B7280; margin-bottom: 6px; display: flex; gap: 8px; flex-wrap: wrap; align-items: center;">
                                <strong><?php echo $meta['label']; ?></strong>
                                <span>· <?php echo date('d.m.Y H:i', strtotime($m['created_at'])); ?></span>
                                <?php if ($m['direction'] === 'out' && $m['status']): ?>
                                    <span class="badge <?php echo $m['status'] === 'failed' ? 'badge-danger' : ($m['status'] === 'sent' ? 'badge-success' : 'badge-warning'); ?>">
                                        <?php echo $statusNames[$m['status']] ?? $m['status']; ?>
                                    </span>
                                <?php endif; ?>
                                <?php if (!empty($m['order_id'])): ?>
                                    <span style="font-size: 11px; color: #6B7280;">заказ #<?php echo (int)$m['order_id']; ?></span>
                                <?php endif; ?>
                                <?php if (!empty($m['alert_id'])): ?>
                                    <a href="/admin/alerts/view.php?id=<?php echo (int)$m['alert_id']; ?>" style="font-size: 11px; color: #7F56D9;">алерт #<?php echo (int)$m['alert_id']; ?></a>
                                <?php endif; ?>
                            </div>
                            <div style="white-space: pre-wrap; line-height: 1.5; font-size: 14px;">
                                <?php echo htmlspecialchars((string)$m['text']) ?: '<span style="color:#9CA3AF;">(пусто)</span>'; ?>
                            </div>
                            <?php if ($m['direction'] === 'out' && $m['status'] === 'failed' && !empty($m['error'])): ?>
                                <div style="margin-top: 6px; font-size: 12px; color: #DC2626;">⚠ <?php echo htmlspecialchars((string)$m['error']); ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <div style="margin-top: 16px; border-top: 1px solid #E5E7EB; padding-top: 16px;">
                <form method="post" action="/admin/max/reply.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="phone" value="<?php echo htmlspecialchars($phone); ?>">
                    <label style="display: block; font-size: 13px; font-weight: 600; color: #374151; margin-bottom: 6px;">Ответить пользователю в «Макс»</label>
                    <textarea name="message" rows="3" required maxlength="4096" placeholder="Введите сообщение…"
                              style="width: 100%; padding: 10px 12px; border: 1px solid #D1D5DB; border-radius: 8px; resize: vertical; font-family: inherit; font-size: 14px; box-sizing: border-box;"></textarea>
                    <div style="margin-top: 8px;">
                        <button type="submit" class="btn btn-primary">Отправить в «Макс»</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

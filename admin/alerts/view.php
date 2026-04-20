<?php
/**
 * Админ: детальная страница алерта + история чата + смена статуса
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: /admin/alerts/');
    exit;
}

$stmt = $db->prepare('SELECT * FROM support_alerts WHERE id = ? LIMIT 1');
$stmt->execute([$id]);
$alert = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$alert) {
    header('Location: /admin/alerts/');
    exit;
}

// Подтягиваем историю чата (если была привязка)
$chatMessages = [];
if ($alert['chat_session_id']) {
    $stmt = $db->prepare(
        "SELECT role, content, created_at FROM ai_chat_messages
         WHERE session_id = ? AND role IN ('user','assistant')
         ORDER BY id ASC LIMIT 100"
    );
    $stmt->execute([$alert['chat_session_id']]);
    $chatMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$pageTitle = 'Алерт #' . $alert['id'];
$statusNames = [
    'new' => 'Новый',
    'in_progress' => 'В работе',
    'resolved' => 'Решён',
    'closed' => 'Закрыт',
];
$statusBadges = [
    'new' => 'badge-warning',
    'in_progress' => 'badge-info',
    'resolved' => 'badge-success',
    'closed' => 'badge-purple',
];

require_once __DIR__ . '/../../includes/session.php';
$csrfToken = generateCSRFToken();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <a href="/admin/alerts/" style="font-size: 13px; color: #6B7280; text-decoration: none;">← Все алерты</a>
    <h1>Алерт #<?php echo $alert['id']; ?></h1>
    <p>Создан: <?php echo date('d.m.Y H:i', strtotime($alert['created_at'])); ?></p>
</div>

<div style="display: grid; grid-template-columns: 1fr 380px; gap: 20px; align-items: start;">
    <div>
        <div class="content-card">
            <div class="card-body">
                <h3 style="margin-top: 0;">Описание проблемы</h3>
                <?php if ($alert['ai_summary']): ?>
                    <div style="background: #F5F3FF; border-left: 3px solid #7F56D9; padding: 12px 16px; margin-bottom: 16px; border-radius: 4px;">
                        <strong style="color: #7F56D9; font-size: 12px;">ИИ-резюме</strong>
                        <?php if ($alert['ai_category']): ?>
                            <span class="badge badge-info" style="margin-left: 8px;"><?php echo htmlspecialchars($alert['ai_category']); ?></span>
                        <?php endif; ?>
                        <div style="margin-top: 6px;"><?php echo htmlspecialchars($alert['ai_summary']); ?></div>
                    </div>
                <?php endif; ?>
                <div style="white-space: pre-wrap; line-height: 1.6;"><?php echo htmlspecialchars($alert['description']); ?></div>
                <?php if ($alert['page_url']): ?>
                    <p style="margin-top: 16px; font-size: 13px; color: #6B7280;">
                        Страница: <a href="<?php echo htmlspecialchars($alert['page_url']); ?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($alert['page_url']); ?></a>
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!empty($chatMessages)): ?>
            <div class="content-card" style="margin-top: 20px;">
                <div class="card-body">
                    <h3 style="margin-top: 0;">История чата с ИИ-консультантом</h3>
                    <div style="max-height: 500px; overflow-y: auto; background: #F9FAFB; padding: 12px; border-radius: 8px;">
                        <?php foreach ($chatMessages as $m): ?>
                            <div style="margin-bottom: 12px; padding: 10px 14px; border-radius: 10px; <?php echo $m['role'] === 'user' ? 'background: #7F56D9; color: white; margin-left: 40px;' : 'background: white; color: #1F2937; margin-right: 40px; border: 1px solid #E5E7EB;'; ?>">
                                <div style="font-size: 11px; opacity: 0.7; margin-bottom: 4px;">
                                    <?php echo $m['role'] === 'user' ? '👤 Пользователь' : '🤖 ИИ'; ?>
                                    • <?php echo date('d.m H:i', strtotime($m['created_at'])); ?>
                                </div>
                                <div style="white-space: pre-wrap; line-height: 1.45; font-size: 14px;"><?php echo htmlspecialchars($m['content']); ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <div>
        <div class="content-card">
            <div class="card-body">
                <h3 style="margin-top: 0;">Контакты</h3>
                <p style="margin-bottom: 8px;"><strong><?php echo htmlspecialchars($alert['user_name']); ?></strong></p>
                <p style="margin: 0 0 6px; font-size: 14px;">
                    📧 <a href="mailto:<?php echo htmlspecialchars($alert['user_email']); ?>"><?php echo htmlspecialchars($alert['user_email']); ?></a>
                </p>
                <?php if ($alert['user_phone']): ?>
                    <p style="margin: 0 0 6px; font-size: 14px;">
                        📱 <a href="tel:<?php echo htmlspecialchars($alert['user_phone']); ?>"><?php echo htmlspecialchars($alert['user_phone']); ?></a>
                    </p>
                <?php endif; ?>
                <?php if ($alert['user_id']): ?>
                    <p style="margin: 8px 0 0; font-size: 13px; color: #6B7280;">
                        Авторизован (user_id: <?php echo (int)$alert['user_id']; ?>)
                    </p>
                <?php endif; ?>
            </div>
        </div>

        <div class="content-card" style="margin-top: 16px;">
            <div class="card-body">
                <h3 style="margin-top: 0;">
                    Статус
                    <span class="badge <?php echo $statusBadges[$alert['status']] ?? 'badge-warning'; ?>" style="margin-left: 8px;">
                        <?php echo $statusNames[$alert['status']] ?? $alert['status']; ?>
                    </span>
                </h3>

                <form method="post" action="/admin/alerts/update.php">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="id" value="<?php echo $alert['id']; ?>">

                    <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin: 12px 0 4px;">Новый статус</label>
                    <select name="status" style="width: 100%; padding: 8px 10px; border: 1px solid #E5E7EB; border-radius: 6px;">
                        <?php foreach ($statusNames as $key => $name): ?>
                            <option value="<?php echo $key; ?>" <?php echo $alert['status'] === $key ? 'selected' : ''; ?>><?php echo $name; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label style="display: block; font-size: 12px; font-weight: 600; color: #374151; margin: 12px 0 4px;">Заметки администратора</label>
                    <textarea name="admin_notes" rows="4" style="width: 100%; padding: 8px 10px; border: 1px solid #E5E7EB; border-radius: 6px; resize: vertical; font-family: inherit;"><?php echo htmlspecialchars($alert['admin_notes'] ?? ''); ?></textarea>

                    <button type="submit" class="btn btn-primary" style="margin-top: 12px; width: 100%;">Сохранить</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

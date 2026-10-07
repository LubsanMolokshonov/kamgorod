<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Admin.php';
require_once __DIR__ . '/../../classes/BlogComment.php';
require_once __DIR__ . '/../../includes/session.php';
Admin::verifySession();
$blogComments = new BlogComment($db);
$status = $_GET['status'] ?? 'pending';
if (!in_array($status, ['pending', 'approved', 'rejected'], true)) $status = 'pending';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!is_string($_POST['csrf_token'] ?? null) || !validateCSRFToken($_POST['csrf_token'])) { http_response_code(403); exit('Недействительный токен'); }
    $action = $_POST['action'] ?? '';
    if (in_array($action, ['approved', 'rejected'], true)) $blogComments->moderate((int)($_POST['id'] ?? 0), $action);
    header('Location: /admin/reviews/?section=blog&status=' . $status); exit;
}
$storage = new Database($db);
$before = max(0, (int)($_GET['before'] ?? 0));
$rows = $storage->query('SELECT c.*, p.title, p.slug, parent.author_name AS parent_name, parent.body AS parent_body FROM blog_comments c JOIN publications p ON p.id = c.publication_id LEFT JOIN blog_comments parent ON parent.id = c.parent_id WHERE c.status = ? AND (? = 0 OR c.id < ?) ORDER BY c.id DESC LIMIT 101', [$status, $before, $before]);
$more = count($rows) > 100; $rows = array_slice($rows, 0, 100);
$e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$csrf = generateCSRFToken();
?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Обсуждения блога — модерация</title>
<style>body{font:16px system-ui;max-width:1000px;margin:30px auto;padding:0 16px;color:#222}article{border:1px solid #ddd;border-radius:12px;padding:20px;margin:20px 0;overflow-wrap:anywhere}nav{display:flex;gap:20px;flex-wrap:wrap}blockquote{background:#f5f5f5;padding:12px;margin:12px 0}button{padding:8px 14px;cursor:pointer}form{display:inline}small{color:#666}.body{white-space:pre-wrap}</style></head><body>
<a href="/admin/reviews/">← Отзывы продуктов</a><h1>Обсуждения блога</h1>
<nav><?php foreach (['pending' => 'На модерации', 'approved' => 'Одобрено', 'rejected' => 'Отклонено'] as $value => $label): ?><a href="?section=blog&amp;status=<?= $value ?>" <?= $status === $value ? 'aria-current="page"' : '' ?>><?= $label ?></a><?php endforeach; ?></nav>
<?php if (!$rows): ?><p>Сообщений в этом статусе нет.</p><?php endif; ?>
<?php foreach ($rows as $row): ?><article>
<a href="/blog/<?= $e($row['slug']) ?>/#comment-<?= (int)$row['id'] ?>"><?= $e($row['title']) ?></a>
<p><strong><?= $e($row['author_name']) ?></strong> <?= $e($row['author_role']) ?> · <?= $e($row['created_at']) ?><?php if ($row['rating'] !== null): ?> · Оценка <?= (int)$row['rating'] ?>/5<?php endif; ?></p>
<?php if ($row['parent_id']): ?><blockquote>Ответ для <?= $e($row['parent_name']) ?><p><?= nl2br($e($row['parent_body'])) ?></p></blockquote><?php endif; ?>
<p class="body"><?= $e($row['body']) ?></p><p><small><?= $e($row['moderation_reason']) ?></small></p>
<?php foreach (['approved' => 'Одобрить', 'rejected' => 'Отклонить'] as $action => $label): if ($action === $row['status']) continue; ?><form method="post"><input type="hidden" name="csrf_token" value="<?= $e($csrf) ?>"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="action" value="<?= $action ?>"><button><?= $label ?></button></form> <?php endforeach; ?>
</article><?php endforeach; ?>
<?php if ($more): ?><a href="?section=blog&amp;status=<?= $status ?>&amp;before=<?= (int)end($rows)['id'] ?>">Следующие сообщения</a><?php endif; ?>
</body></html>

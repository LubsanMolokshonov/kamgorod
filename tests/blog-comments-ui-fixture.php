<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/BlogComment.php';
if (getenv('BLOG_UI_TEST') !== '1' || !in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost','127.0.0.1'], true)) throw new RuntimeException('Только локальная UI-проверка');
set_exception_handler(static function (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
if (($argv[1] ?? '') === '--session') {
    ini_set('session.use_strict_mode', '0'); // Только локальная тестовая сессия с узнаваемым ID.
    $sid = 'blogtest' . bin2hex(random_bytes(16)); session_id($sid); session_start();
    if (($argv[2] ?? '') === 'admin') { $_SESSION['admin_id'] = 1; $_SESSION['admin_username'] = 'local-blog-test'; $_SESSION['admin_role'] = 'admin'; }
    else { $_SESSION['user_id'] = (int)$db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn(); }
    session_write_close(); echo $sid; exit;
}
if (($argv[1] ?? '') === '--clear-session') {
    if (!preg_match('/^blogtest[a-f0-9]{32}$/D', $argv[2] ?? '')) throw new RuntimeException('Только тестовая сессия');
    session_id($argv[2]); session_start(); session_destroy(); exit;
}
$token = (($argv[1] ?? '') === '--clean-http' ? 'bd' : 'bc') . str_repeat('0', 30);
if (($argv[1] ?? '') === '--clean' || ($argv[1] ?? '') === '--clean-http') {
    $stmt = $db->prepare('SELECT id FROM blog_comments WHERE vote_token = ? ORDER BY id DESC'); $stmt->execute([$token]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) { $q=$db->prepare('DELETE FROM blog_comments WHERE id=?');$q->execute([$id]); }
    echo "clean\n"; exit;
}
$post = $db->query("SELECT id,slug FROM publications WHERE source='blog' AND status='published' LIMIT 1")->fetch();
if (!$post) throw new RuntimeException('Нужна опубликованная локальная статья');
$service = new BlogComment($db, static fn() => ['ok'=>true]);
$ids=[];
for ($i=0; $i<24; $i++) {
    $r=$service->submit(['publication_id'=>$post['id'],'author_name'=>'Тестовый читатель','body'=>'Комментарий для проверки '.$i,'rating'=>$i===0?'4':'','request_key'=>bin2hex(random_bytes(16))],$token,null,'');
    $ids[]=$r['id'];
}
$reply=$service->submit(['publication_id'=>$post['id'],'parent_id'=>end($ids),'author_name'=>'Учитель','body'=>'Спасибо за уточнение','request_key'=>bin2hex(random_bytes(16))],$token,null,'');
echo json_encode(['url'=>'/blog/'.$post['slug'].'/','article_id'=>$post['id'],'root_id'=>end($ids),'reply_id'=>$reply['id']]);

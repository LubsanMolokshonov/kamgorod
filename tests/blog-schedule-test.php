<?php
/** Проверки даты, повторного запуска, конфликта и публикации ссылок без production. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/blog-schedule.php';
require_once __DIR__ . '/../includes/url-helper.php';
function expect(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(callable $fn, string $message): void {
    try { $fn(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
$root = dirname(__DIR__);
$file = $root . '/editorial/schedules/first-five-2026-10-14.json';
$entries = blogScheduleLoad($root, $file);
expect(count($entries) === 5, 'Пять статей');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, email TEXT);
    CREATE TABLE publication_types (id INTEGER PRIMARY KEY, slug TEXT);
    CREATE TABLE publication_tags (id INTEGER PRIMARY KEY, slug TEXT);
    CREATE TABLE publication_tag_relations (publication_id INTEGER, tag_id INTEGER);
    CREATE TABLE publications (id INTEGER PRIMARY KEY, user_id INTEGER, publication_type_id INTEGER, slug TEXT UNIQUE,
    title TEXT, annotation TEXT, content TEXT, meta_title TEXT, meta_description TEXT, noindex INTEGER,
    source TEXT, status TEXT, cover_status TEXT, cover_image_url TEXT)');
$pdo->exec("INSERT INTO users VALUES (1, 'blog@fgos.pro'); INSERT INTO publication_types VALUES (1, 'methodology')");
$calls = 0;
$publisher = function(PDO $db, array $p) use (&$calls): int {
    $calls++;
    $values = ['user_id'=>1, 'publication_type_id'=>1, 'source'=>'blog', 'status'=>'published', 'cover_status'=>'done'];
    foreach (['slug','title','annotation','content','meta_title','meta_description','noindex','cover_image_url'] as $key) $values[$key] = $p[$key];
    $q=$db->prepare('INSERT INTO publications ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');
    $q->execute(array_values($values)); return (int)$db->lastInsertId();
};
$before = new DateTimeImmutable('2026-10-14T05:59:59+00:00');
$at = new DateTimeImmutable('2026-10-14T06:00:00+00:00');
$r=blogScheduleProcess($pdo,$entries,$before,true,$publisher);
expect($calls===0 && $r[0]['status']==='scheduled', 'До 13:00 +07:00 публикации нет');
$r=blogScheduleProcess($pdo,$entries,$at,false,$publisher);
expect($calls===0 && $r[0]['status']==='due', 'Dry-run ничего не пишет');
$r=blogScheduleProcess($pdo,$entries,$at,true,$publisher);
expect($calls===1 && $r[0]['status']==='published' && $r[1]['status']==='scheduled', 'На границе выходит только первая');
$firstId=$r[0]['id'];
$r=blogScheduleProcess($pdo,$entries,$at,true,$publisher);
expect($calls===1 && $r[0]['id']===$firstId && $r[0]['status']==='already_published', 'Повторный запуск не дублирует');
$html='<p>Читайте <a href="/blog/podelki-dlya-papy/"><strong>поделки</strong></a>, <a href="https://example.com/blog/other/">внешний сайт</a> и <a href="/blog/den-otca-v-detskom-sadu/">День отца</a>.</p>';
$out=renderPublishedBlogLinks($html,$pdo);
expect(!str_contains($out,'href="/blog/podelki-dlya-papy/"') && str_contains($out,'<strong>поделки</strong>'), 'Будущая ссылка сохраняет текст');
expect(str_contains($out,'href="https://example.com/blog/other/"') && str_contains($out,'href="/blog/den-otca-v-detskom-sadu/"'), 'Рабочие и внешние ссылки сохранены');
$r=blogScheduleProcess($pdo,$entries,new DateTimeImmutable('2026-10-17T09:00:00+03:00'),true,$publisher);
expect($calls===4 && $r[4]['status']==='scheduled', 'Пропущенные запуски догоняются без выпуска пятой');
expect(str_contains(renderPublishedBlogLinks($html,$pdo),'href="/blog/podelki-dlya-papy/"'), 'Ссылка появилась после выхода адресата');
$pdo->exec("UPDATE publications SET title='Чужая правка' WHERE id=".$firstId);
fails(fn()=>blogScheduleProcess($pdo,$entries,new DateTimeImmutable('2026-10-18T13:00:00+07:00'),true,$publisher),'Конфликт нельзя игнорировать');
expect($calls===4, 'Полный preflight остановил пятую запись при конфликте');
$restore=$pdo->prepare('UPDATE publications SET title=? WHERE id=?');
$restore->execute([$entries[0]['package']['title'],$firstId]);
$nextEntries=blogScheduleLoad($root,$root.'/editorial/schedules/next-five-2026-10-14.json');
expect(count($nextEntries)===5,'Следующая пятёрка содержит пять статей');
$allEntries=array_merge($entries,$nextEntries);
blogScheduleProcess($pdo,$allEntries,new DateTimeImmutable('2026-10-18T09:00:00+03:00'),true,$publisher);
expect($calls===5,'К 18 октября вышла первая пятёрка');
foreach($nextEntries as $i=>$entry){
    $expectedAt=new DateTimeImmutable('2026-10-'.(19+$i).'T09:00:00+03:00');
    expect($entry['at']==$expectedAt,'Ежедневная дата и 09:00 МСК следующей пятёрки');
    $beforeCount=$calls;
    blogScheduleProcess($pdo,$allEntries,$expectedAt->modify('-1 second'),true,$publisher);
    expect($calls===$beforeCount,'За секунду до срока новая статья скрыта');
    $daily=blogScheduleProcess($pdo,$allEntries,$expectedAt,true,$publisher);
    expect($calls===$beforeCount+1 && $daily[5+$i]['status']==='published','В 09:00 выходит ровно одна статья');
    blogScheduleProcess($pdo,$allEntries,$expectedAt,true,$publisher);
    expect($calls===$beforeCount+1,'Повтор ежедневного запуска не дублирует статью');
}
$schedule=json_decode(file_get_contents($file),true);
$temporary=$root.'/editorial/schedules/test-invalid-'.getmypid().'.json';
try {
    $changed=$schedule; $changed['entries'][0]['approved_sha256']=str_repeat('0',64);
    file_put_contents($temporary,json_encode($changed)); fails(fn()=>blogScheduleLoad($root,$temporary),'Изменённый хеш не принят');
    $changed=$schedule; $changed['entries'][2]['inline_assets_sha256']=[];
    file_put_contents($temporary,json_encode($changed)); fails(fn()=>blogScheduleLoad($root,$temporary),'Иллюстрации нельзя пропустить');
    $changed=$schedule; $changed['entries'][0]['publish_at']='2026-02-30T13:00:00+07:00';
    file_put_contents($temporary,json_encode($changed)); fails(fn()=>blogScheduleLoad($root,$temporary),'Невалидная дата не принята');
    $changed=$schedule; $changed['entries'][0]['publish_at']='2026-10-14T13:00:00';
    file_put_contents($temporary,json_encode($changed)); fails(fn()=>blogScheduleLoad($root,$temporary),'Дата без timezone не принята');
} finally { @unlink($temporary); }
echo "OK: дата и часовой пояс, dry-run, повтор, catch-up, конфликт, хеши, ссылки.\n";

<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../classes/BlogComment.php';
require_once __DIR__ . '/../includes/blog-discussion.php';
set_exception_handler(static function (Throwable $e) { fwrite(STDERR, $e->getMessage() . "\n"); exit(1); });
$testDb = getenv('BLOG_TEST_DB');
if (!$testDb || !preg_match('/^pedagogy_blog_test_[a-z0-9_]+$/D', $testDb)) throw new RuntimeException('Задайте отдельную БД BLOG_TEST_DB=pedagogy_blog_test_…');
$pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . $testDb . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$approve = static fn($text) => ['ok' => true, 'reason' => 'Тестовый модератор'];
$service = new BlogComment($pdo, $approve);
function bcCheck($ok, $message) { if (!$ok) throw new RuntimeException($message); echo "OK: $message\n"; }
function bcReject(callable $f, $message) { try { $f(); } catch (InvalidArgumentException $e) { bcCheck(true, $message); return; } throw new RuntimeException($message); }
function bcInput(array $extra = []): array { return array_merge(['publication_id' => 1, 'author_name' => 'Читатель', 'author_role' => '', 'body' => 'Полезная статья', 'request_key' => bin2hex(random_bytes(16))], $extra); }
if (($argv[1] ?? '') === '--limit-worker') {
    $payload = json_decode(base64_decode($argv[2]), true);
    echo json_encode(['allowed' => blogConsumeLimit($payload['key'], 3)]); exit;
}
if (($argv[1] ?? '') === '--worker') {
    $payload = json_decode(base64_decode($argv[2]), true);
    try { echo json_encode($service->submit($payload, str_repeat('d', 32), null, '127.0.0.1')); }
    catch (InvalidArgumentException $e) { echo json_encode(['rejected' => true]); }
    exit;
}
$pdo->exec('CREATE TABLE publications (id INT UNSIGNED PRIMARY KEY, slug VARCHAR(120), title VARCHAR(120), source VARCHAR(20), status VARCHAR(20), redirect_to_slug VARCHAR(120))');
$pdo->exec("INSERT INTO publications VALUES (1,'blog-test','Статья','blog','published',NULL),(2,'another','Другая','blog','published',NULL),(3,'draft','Черновик','blog','draft',NULL),(4,'ugc','Материал','upload','published',NULL)");
$sql = file_get_contents(__DIR__ . '/../database/migrations/191_blog_comments.sql');
$pdo->exec($sql); $pdo->exec($sql);
bcCheck(true, 'повторная миграция');
$token = str_repeat('a', 32);
$input = bcInput(['rating' => '5']);
$root = $service->submit($input, $token, null, '127.0.0.1');
bcCheck($root['status'] === 'approved', 'автоодобрение');
$again = $service->submit($input, $token, null, '127.0.0.1', static function () { throw new RuntimeException('Лимит не должен расходоваться'); });
bcCheck($again['duplicate'] && $again['id'] === $root['id'], 'повтор запроса без дубля и расхода лимита');
bcReject(fn() => $service->submit(array_merge($input, ['body' => 'Изменено']), $token, null, ''), 'ключ нельзя использовать для другого текста');
bcReject(fn() => $service->submit(bcInput(['rating' => '4']), $token, null, ''), 'одна оценка браузера');
$plain = $service->submit(bcInput(['body' => 'Вопрос без оценки']), $token, 10, '');
$reply = $service->submit(bcInput(['parent_id' => $root['id'], 'body' => 'Ответ']), $token, null, '');
$nested = $service->submit(bcInput(['parent_id' => $reply['id'], 'body' => 'Ответ на ответ']), $token, null, '');
bcCheck(count($service->threads(1)['rows']) === 4 && $service->stats(1) === ['count' => 1, 'avg' => 5.0, 'comments' => 4], 'ветки и рейтинг без учёта ответов');
bcReject(fn() => $service->submit(bcInput(['parent_id' => $root['id'], 'rating' => '3']), $token, null, ''), 'у ответа нет оценки');
bcReject(fn() => $service->submit(bcInput(['parent_id' => $root['id'], 'publication_id' => 2]), $token, null, ''), 'чужая статья');
foreach ([3,4] as $id) bcReject(fn() => $service->submit(bcInput(['publication_id' => $id]), $token, null, ''), 'черновик и не блог запрещены');
foreach (['0','6','3.1','3oops'] as $rating) bcReject(fn() => $service->submit(bcInput(['rating' => $rating]), $token, null, ''), 'строгая оценка ' . $rating);
bcReject(fn() => $service->submit(bcInput(['body' => str_repeat('я', 2001)]), $token, null, ''), 'длина текста');
$service->moderate($reply['id'], 'rejected');
bcCheck(!$service->visible($nested['id'], 1) && count($service->threads(1)['rows']) === 2 && $service->stats(1)['comments'] === 2, 'скрытие промежуточного родителя скрывает потомков');
$service->moderate($root['id'], 'rejected');
bcCheck($service->stats(1)['count'] === 0, 'отклонённая оценка не влияет на рейтинг');
bcReject(fn() => $service->submit(bcInput(['parent_id' => $root['id']]), $token, null, ''), 'ответ на скрытый отзыв запрещён');
$pending = (new BlogComment($pdo, static fn() => ['ok' => false]))->submit(bcInput(), $token, null, '');
$failed = (new BlogComment($pdo, static function () { throw new RuntimeException('mock outage'); }))->submit(bcInput(), $token, null, '');
bcCheck($pending['status'] === 'pending' && $failed['status'] === 'pending', 'спорный текст и сбой остаются на модерации');
$service->moderate($failed['id'], 'approved'); bcCheck($service->visible($failed['id'], 1), 'ручное одобрение');
$pdo->exec("INSERT INTO blog_comments(publication_id,author_name,body,vote_token,request_key,request_hash,status) SELECT 1,'Тест','Текст',REPEAT('f',32),REPLACE(UUID(),'-',''),REPEAT('f',64),'approved' FROM information_schema.columns LIMIT 22");
$pdo->exec('UPDATE blog_comments SET root_id=id WHERE root_id IS NULL');
$first = $service->threads(1); $second = $service->threads(1, $first['next']);
bcCheck(count($first['rows']) === 20 && $first['next'] && count($second['rows']) > 0 && !array_intersect(array_column($first['rows'],'id'), array_column($second['rows'],'id')), 'пагинация по 20 веток без дублей');
$evil = '</script><script>alert(1)</script>';
$rows = [['id'=>999,'root_id'=>999,'parent_id'=>null,'author_name'=>$evil,'author_role'=>'','body'=>$evil,'rating'=>4,'created_at'=>'2026-01-01 10:00:00']];
bcCheck(!str_contains(blogDiscussionHtml($rows), '<script>'), 'HTML экранирован');
$schema = blogDiscussionSchema(['@id'=>'https://example.test/#article','url'=>'https://example.test/'], ['comments'=>1,'avg'=>4,'count'=>1], $rows);
$encoded = json_encode($schema, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
bcCheck(!str_contains($encoded, '</script>') && json_decode($encoded,true)['aggregateRating']['ratingCount'] === 1, 'безопасный JSON-LD');
$limitKey = 'test:' . bin2hex(random_bytes(8));
try { bcCheck(blogConsumeLimit($limitKey, 1) && !blogConsumeLimit($limitKey, 1), 'атомарный лимит'); }
finally { unlink(sys_get_temp_dir() . '/blog_rl_' . hash('sha256', $limitKey)); }
function concurrent(array $inputs, string $mode = '--worker'): array {
    $jobs = [];
    foreach ($inputs as $input) {
        $p = proc_open([PHP_BINARY, __FILE__, $mode, base64_encode(json_encode($input))], [1=>['pipe','w'],2=>['pipe','w']], $pipes);
        $jobs[] = [$p, $pipes];
    }
    $out=[];
    foreach ($jobs as [$p,$pipes]) { $raw=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($p)!==0) throw new RuntimeException($err); $out[]=json_decode($raw,true,512,JSON_THROW_ON_ERROR); }
    return $out;
}
$raceInput=bcInput(); $out=concurrent([$raceInput,$raceInput]);
bcCheck($out[0]['id']===$out[1]['id'], 'конкурентный повтор создаёт одну строку');
$out=concurrent([bcInput(['rating'=>'3']),bcInput(['rating'=>'4'])]);
bcCheck(count(array_filter($out, static fn($r)=>!empty($r['rejected'])))===1, 'конкурентные оценки: сохранена ровно одна');
$limitKey = 'race:' . bin2hex(random_bytes(8));
try {
    $out = concurrent(array_fill(0, 8, ['key' => $limitKey]), '--limit-worker');
    bcCheck(count(array_filter($out, static fn($r) => $r['allowed'])) === 3, 'конкурентный лимит: ровно три из восьми');
} finally { unlink(sys_get_temp_dir() . '/blog_rl_' . hash('sha256', $limitKey)); }
$manualWins = new BlogComment($pdo, static function () use ($pdo) {
    $pdo->exec("UPDATE blog_comments SET status='rejected', moderated_at=NOW() WHERE id=LAST_INSERT_ID()");
    return ['ok'=>true];
});
$manual = $manualWins->submit(bcInput(), $token, null, '');
bcCheck($manual['status'] === 'rejected', 'ручное решение не перезаписывается поздним ответом ИИ');
echo "PASS\n";

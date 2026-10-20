<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../scripts/lib/tg-blog/publisher.php';
if (!in_array(DB_HOST, ['localhost','127.0.0.1'],true)) { throw new RuntimeException('Только локальная MySQL'); }
function assertTg(bool $ok,string $label): void { if (!$ok) { throw new RuntimeException($label); } echo "OK: $label\n"; }
function throwsTg(Closure $fn,string $label): void {
    try { $fn(); } catch (RuntimeException $e) { assertTg(true,$label); return; }
    throw new LogicException('Ожидалась ошибка: ' . $label);
}
$db = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',DB_USER,DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
$migration = file_get_contents(__DIR__ . '/../database/migrations/193_telegram_blog.sql');
foreach (explode(';',str_replace('CREATE TABLE IF NOT EXISTS','CREATE TEMPORARY TABLE IF NOT EXISTS',$migration)) as $sql) {
    if (trim($sql) !== '') { $db->exec($sql); }
}
$db->exec("CREATE TEMPORARY TABLE publications (id BIGINT PRIMARY KEY,source VARCHAR(32),status VARCHAR(32),
    title VARCHAR(255),slug VARCHAR(255),annotation TEXT,content TEXT,cover_image_url TEXT,published_at DATETIME,redirect_to_slug TEXT)");
$insert = $db->prepare("INSERT INTO publications VALUES (?,'blog','published','Заголовок',?,'Описание','<p>Текст</p>','/cover.png','2026-10-01',NULL)");
$add = static function(int $id) use ($insert): void { $insert->execute([$id,'article-'.$id]); };
$tg = new class implements TgBlogGateway {
    public array $posts=[]; public ?Throwable $error=null;
    public function preflight(): string { return '-10042'; }
    public function send(array $photo,string $caption): int {
        if ($this->error) { throw $this->error; } $this->posts[]=$caption; return count($this->posts);
    }
};
$site = new class implements TgBlogSiteGateway {
    public bool $down=false;
    public function prepare(array $a): array {
        if ($this->down) { throw new RuntimeException('Сайт недоступен'); }
        $im=imagecreatetruecolor(12,8); ob_start(); imagepng($im); $bytes=ob_get_clean();
        return ['bytes'=>$bytes,'mime'=>'image/png','sha256'=>hash('sha256',$bytes)];
    }
};
$p = new TgBlogPublisher($db,$tg,$site,'-10042','https://fgos.pro');
$day = static fn(string $date,string $time='12:00'): DateTimeImmutable => new DateTimeImmutable("$date $time",new DateTimeZone('Europe/Moscow'));
$add(1);$add(2);$add(3);$add(4);$add(5);$add(6);
$db->exec("UPDATE publications SET status='draft' WHERE id=3");
$db->exec("UPDATE publications SET source='user' WHERE id=4");
$db->exec("UPDATE publications SET redirect_to_slug='replacement' WHERE id=5");
$db->exec("UPDATE publications SET published_at='2099-01-01' WHERE id=6");
assertTg(count($p->preview())===2,'Предпросмотр исключает черновики, чужие разделы, редиректы и будущие даты');
assertTg((int)$db->query('SELECT COUNT(*) FROM tg_blog_deliveries')->fetchColumn()===0,'Предпросмотр не пишет в очередь');
throwsTg(fn()=>$p->run('archive',$day('2026-10-19')),'До инициализации отправка запрещена');
$p->initialize();
throwsTg(fn()=>$p->initialize(),'Повторная инициализация запрещена');
$add(7);$add(8);
assertTg($p->run('archive',$day('2026-10-19','09:59'))['state']==='too_early','Архив не отправляется до 10');
assertTg($p->run('archive',$day('2026-10-19','10:00'))['publication_id']===1,'Первая архивная статья в 10');
assertTg($p->run('archive',$day('2026-10-19'))['state']==='daily_limit','Повторный запуск не даёт второй архив');
assertTg($p->run('new',$day('2026-10-19'))['publication_id']===7,'Одна новая статья в 12');
assertTg($p->run('new',$day('2026-10-19'))['state']==='daily_limit','Лимит новой очереди');
assertTg($p->run('archive',$day('2026-10-20'))['publication_id']===2,'На следующий день следующий архив');
assertTg($p->run('new',$day('2026-10-20'))['publication_id']===8,'Новые статьи сохраняют очередь');
assertTg($p->run('archive',$day('2026-10-21'))['state']==='empty','Пустой архив не подменяется новой статьёй');
$db->exec("UPDATE publications SET title='Правка',slug='changed' WHERE id=1");
assertTg($p->run('archive',$day('2026-10-22'))['state']==='empty','Правки статьи не создают повтор');
$add(9);$site->down=true;
throwsTg(fn()=>$p->run('new',$day('2026-10-21')),'Недоступный сайт блокирует отправку');
$site->down=false;$tg->error=new TgBlogRejected('Отказ 403');
throwsTg(fn()=>$p->run('new',$day('2026-10-22')),'Однозначный отказ сохранён');
$tg->error=null;
assertTg($p->run('new',$day('2026-10-22'))['state']==='daily_limit','Отказ не вызывает повтор в тот же день');
assertTg($p->run('new',$day('2026-10-23'))['publication_id']===9,'После отказа безопасный повтор на следующий день');
$add(10);$tg->error=new TgBlogUnknown('Обрыв');
throwsTg(fn()=>$p->run('new',$day('2026-10-24')),'Неопределённый результат сохранён');
$tg->error=null;
throwsTg(fn()=>$p->run('new',$day('2026-10-25')),'Неизвестная отправка блокирует очередь на следующий день');
assertTg($db->query("SELECT state FROM tg_blog_deliveries WHERE publication_id=10")->fetchColumn()==='unknown','Состояние unknown сохраняется');
assertTg(count($tg->posts)===5,'Отправлены только пять подтверждённых анонсов');
// Аварийный выход до сохранения ответа также запрещает повтор.
$db->exec("UPDATE tg_blog_deliveries SET state='sending' WHERE publication_id=10");
throwsTg(fn()=>$p->run('new',$day('2026-10-26')),'Аварийный sending превращается в unknown');
assertTg($db->query("SELECT state FROM tg_blog_deliveries WHERE publication_id=10")->fetchColumn()==='unknown','Восстановление состояния после аварии');
// Реальная MySQL-блокировка между двумя подключениями.
$other = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',DB_USER,DB_PASS);
$key='tg_blog_test_'.bin2hex(random_bytes(8));
$q=$db->prepare('SELECT GET_LOCK(?,0)');$q->execute([$key]);
try {
    assertTg((int)$q->fetchColumn()===1,'Первый процесс получает блокировку');
    $r=$other->prepare('SELECT GET_LOCK(?,0)');$r->execute([$key]);
    assertTg((int)$r->fetchColumn()===0,'Второй процесс не может отправлять параллельно');
} finally { $q=$db->prepare('SELECT RELEASE_LOCK(?)');$q->execute([$key]); }
echo "Telegram MySQL checks passed; existing tables untouched\n";

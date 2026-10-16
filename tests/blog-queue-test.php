<?php
/** Изолированные проверки добавления в хвост, целостности и смены месяца. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/blog-queue.php';
function queueExpect(bool $ok, string $message): void {
    if (!$ok) { throw new RuntimeException($message); }
}
function queueRejects(callable $call, string $message): void {
    try { $call(); } catch (Throwable $e) { return; }
    throw new RuntimeException($message);
}
function queueFixture(string $root, string $slug): array {
    $dir = $root . '/editorial/articles/' . $slug; mkdir($dir, 0700, true);
    foreach (['brief.md', 'research.md', 'claims.md', 'sources.json', 'article.md', 'review.md'] as $file) {
        file_put_contents($dir . '/' . $file, 'Файл тестового комплекта');
    }
    file_put_contents($dir . '/article.html', '<p>Полезный пример.</p><img src="/assets/images/blog/inline.png" alt="Схема">');
    $meta = ['title' => 'Тест', 'slug' => $slug, 'content-file' => 'article.html',
        'cover-image' => '/assets/images/blog/cover.jpg', 'noindex' => false];
    file_put_contents($dir . '/metadata.json', json_encode($meta));
    $input = $meta; $input['content-file'] = $dir . '/article.html';
    return ['manifest' => 'editorial/articles/' . $slug . '/metadata.json', 'package' => blogPostPackage($input, $root)];
}
$root = sys_get_temp_dir() . '/blog-queue-' . bin2hex(random_bytes(5));
mkdir($root . '/assets/images/blog', 0700, true); mkdir($root . '/editorial/schedules', 0700, true);
try {
    $im = imagecreatetruecolor(800, 450); imagejpeg($im, $root . '/assets/images/blog/cover.jpg');
    imagepng($im, $root . '/assets/images/blog/inline.png'); imagedestroy($im);
    $one = queueFixture($root, 'first'); $two = queueFixture($root, 'second'); $three = queueFixture($root, 'third');
    $legacy = ['version' => 1, 'entries' => [['manifest' => 'editorial/articles/legacy/metadata.json',
        'publish_at' => '2026-10-23T13:00:00+07:00', 'approved_sha256' => str_repeat('a', 64)]]];
    file_put_contents($root . '/editorial/schedules/old.json', json_encode($legacy));
    $now = new DateTimeImmutable('2026-10-16T10:00:00+03:00');
    $file = $root . '/editorial/schedules/daily-queue.json';
    $preview = blogQueueAppend($root, $one['manifest'], $now, false, null);
    queueExpect($preview['publish_at'] === '2026-10-24T09:00:00+03:00' && !is_file($file), 'Dry-run учитывает хвост и не записывает очередь');
    queueRejects(fn()=>blogQueueAppend($root,$one['manifest'],$now,true,null),'Запись без текущего хеша запрещена');
    $r = blogQueueAppend($root,$one['manifest'],$now,true,$one['package']['sha256']);
    queueExpect($r['status']==='queued' && $r['publish_at']===$preview['publish_at'],'Поставлена первая статья');
    $bytes=file_get_contents($file);
    $again=blogQueueAppend($root,$one['manifest'],$now,true,$one['package']['sha256']);
    queueExpect($again['status']==='already_queued' && file_get_contents($file)===$bytes,'Повтор не дублирует и не сдвигает дату');
    $r=blogQueueAppend($root,$two['manifest'],$now,true,$two['package']['sha256']);
    queueExpect($r['publish_at']==='2026-10-25T09:00:00+03:00','Вторая статья получила следующий день');
    $saved=file_get_contents($file);
    $legacy['entries'][0]['publish_at']='2026-10-31T09:00:00+03:00';
    file_put_contents($root.'/editorial/schedules/old.json',json_encode($legacy));
    $r=blogQueueAppend($root,$three['manifest'],$now,true,$three['package']['sha256']);
    queueExpect($r['publish_at']==='2026-11-01T09:00:00+03:00','Хвост переходит в ноябрь');
    $entries=blogScheduleLoad($root,$file);
    queueExpect(count($entries)===3 && $entries[2]['at']->format('m')==='11','Cron принимает ноябрьскую дату');
    queueRejects(fn()=>blogQueueAppend($root,'../outside.json',$now,false,null),'Traversal запрещён');
    queueRejects(fn()=>blogQueueDate('2026-02-30T09:00:00+03:00'),'Невозможная дата запрещена');
    queueRejects(fn()=>blogQueueDate('2026-10-16T09:00:00'),'Дата без часового пояса запрещена');
    queueExpect(blogQueueNextDate([],new DateTimeImmutable('2026-10-16T05:59:59Z'))->format(DATE_ATOM)==='2026-10-16T09:00:00+03:00','Пустая очередь до выпуска использует сегодняшний слот');
    queueExpect(blogQueueNextDate([],new DateTimeImmutable('2026-10-16T06:00:00Z'))->format(DATE_ATOM)==='2026-10-17T09:00:00+03:00','Прошедший слот не используется');
    $lock=fopen($root.'/editorial/schedules/.append.lock','c'); flock($lock,LOCK_EX);
    queueRejects(fn()=>blogQueueAppend($root,$one['manifest'],$now,true,$one['package']['sha256']),'Конкурирующий процесс не может записать очередь');
    flock($lock,LOCK_UN); fclose($lock);
    file_put_contents($root.'/assets/images/blog/inline.png','changed');
    queueRejects(fn()=>blogQueueAppend($root,$one['manifest'],$now,true,$one['package']['sha256']),'Подмена inline-иллюстрации блокирует добавление');
    file_put_contents($file,$saved);
    // Изменившийся HTML уже запланированной статьи нельзя одобрить повторным append.
    file_put_contents($root.'/editorial/articles/first/article.html','<p>Изменённая статья.</p>');
    queueRejects(fn()=>blogQueueAppend($root,$one['manifest'],$now,false,null),'Правка ранее поставленного комплекта требует отдельного пересмотра');
    echo "OK: хвост, dry-run, идемпотентность, ноябрь, часовой пояс, lock, хеши и иллюстрации.\n";
} finally {
    $files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);
    foreach($files as $p){if($p->isDir()){rmdir($p->getPathname());}else{unlink($p->getPathname());}}
    rmdir($root);
}

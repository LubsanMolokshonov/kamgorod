<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../blog-rss.php';
function rssCheck(bool $ok, string $label): void { if (!$ok) throw new RuntimeException($label); echo "OK: $label\n"; }
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec("CREATE TABLE publications(id INTEGER PRIMARY KEY,source TEXT,status TEXT,title TEXT,slug TEXT,annotation TEXT,content TEXT,cover_image_url TEXT,published_at TEXT,redirect_to_slug TEXT);
CREATE TABLE vk_blog_deliveries(publication_id INTEGER,group_id INTEGER,state TEXT,post_id INTEGER);");
$q=$db->prepare('INSERT INTO publications VALUES(?,?,?,?,?,?,?,?,?,?)');
foreach(range(1,9) as $id) $q->execute([$id,'blog','published','Title & <b>text</b>','slug-'.$id,'<p>Announcement &amp; more</p>','<p>Fallback body</p>','/cover.png','2026-10-19 10:00:00',null]);
$db->exec("UPDATE publications SET status='draft' WHERE id=2; UPDATE publications SET source='user' WHERE id=3;
UPDATE publications SET redirect_to_slug='other' WHERE id=4; UPDATE publications SET cover_image_url='' WHERE id=5;
INSERT INTO vk_blog_deliveries VALUES(6,200065283,'sent',538); INSERT INTO vk_blog_deliveries VALUES(7,200065283,'unknown',NULL);
INSERT INTO vk_blog_deliveries VALUES(8,200065283,'sending',NULL); INSERT INTO vk_blog_deliveries VALUES(9,200065283,'pending',NULL);
UPDATE publications SET annotation='' WHERE id=9;");
$image=fn(string $url)=>['url'=>'https://fgos.pro'.$url,'mime'=>'image/png','length'=>123];
$xml=simplexml_load_string(FgosBlogDraftRss::render($db,$image));
rssCheck($xml!==false && count($xml->channel->item)===2,'Only eligible published articles; sent/unknown/sending excluded');
rssCheck((string)$xml->channel->item[0]->link==='https://fgos.pro/blog/slug-1/?utm_source=vk&utm_campaign=blog','Article URL contains VK tracking parameters');
rssCheck((string)$xml->channel->item[0]->guid==='fgos.pro:blog:publication:1','Permanent database ID used as GUID');
rssCheck((string)$xml->channel->item[0]->description==='Announcement & more','Existing announcement cleaned and XML escaped');
rssCheck((string)$xml->channel->item[1]->description==='Fallback body','Fallback uses article text');
rssCheck((string)$xml->channel->item[0]->enclosure['url']==='https://fgos.pro/cover.png','Original cover URL is preserved');
$db->exec("UPDATE publications SET title='Edited',slug='edited',published_at='2026-10-21 10:00:00' WHERE id=1");
$edited=simplexml_load_string(FgosBlogDraftRss::render($db,$image));
rssCheck((string)$edited->channel->item[1]->guid===(string)$xml->channel->item[0]->guid,'Editing title, URL and date preserves GUID');
rssCheck((int)$db->query('SELECT COUNT(*) FROM vk_blog_deliveries')->fetchColumn()===4,'RSS does not write to journal');
rssCheck(FgosBlogDraftRss::cover('https://other.example/cover.png',__DIR__)===null,'Foreign cover host rejected');
rssCheck(FgosBlogDraftRss::cover('/../config/config.php',__DIR__)===null,'Filesystem escape rejected');
$db->exec('DROP TABLE vk_blog_deliveries');
try { FgosBlogDraftRss::render($db,$image); throw new LogicException('Expected DB failure'); }
catch(PDOException) { rssCheck(true,'Database failure is not presented as empty successful feed'); }
echo "RSS checks passed\n";
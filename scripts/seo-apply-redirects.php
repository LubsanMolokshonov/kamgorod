<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Только доказанные побайтовые дубли одного автора. Никаких DELETE/переносов документов. */
require __DIR__.'/../config/database.php';
$args=getopt('',['file:','apply','environment:']);
if(empty($args['file']))throw new RuntimeException('Нужен --file');
$local=in_array(parse_url(SITE_URL,PHP_URL_HOST),['localhost','127.0.0.1'],true);
if(isset($args['apply']) && ($args['environment']??'')!==($local?'local':'production'))throw new RuntimeException('Неверная среда');
$rows=json_decode(file_get_contents($args['file']),true,512,JSON_THROW_ON_ERROR);$report=[];
foreach($rows as $row) {
    $apply=isset($args['apply']);if($apply)$db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM publications WHERE id IN (?,?) ORDER BY id'.($apply?' FOR UPDATE':''));$q->execute([(int)$row['source_id'],(int)$row['target_id']]);$found=array_column($q->fetchAll(),null,'id');
        $a=$found[$row['source_id']]??null;$b=$found[$row['target_id']]??null;
        if(!$a || !$b){$report[]=['source'=>$row['source_slug'],'status'=>'missing'];continue;}
        if($a['slug']!==$row['source_slug'] || $b['slug']!==$row['target_slug'] || (int)$a['user_id']!==(int)$b['user_id'] || (int)$a['user_id']!==(int)$row['user_id'] || $a['content']!==$b['content'] || $a['title']!==$b['title'] || $a['title']!==$row['title'] || !hash_equals($row['content_sha256'],hash('sha256',$a['content']??'')) || $a['status']!=='published' || $b['status']!=='published' || $a['source']==='blog' || $b['source']==='blog' || !empty($b['redirect_to_slug']) || !empty($b['noindex'])) {
            $report[]=['source'=>$row['source_slug'],'status'=>'changed_or_not_equivalent'];continue;
        }
        $q=$db->prepare('SELECT id FROM publications WHERE id=? AND indexable_at IS NOT NULL AND indexable_at<=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 3 HOUR)');$q->execute([$b['id']]);
        if(!$q->fetchColumn()){$report[]=['source'=>$row['source_slug'],'status'=>'target_not_indexable'];continue;}
        if(($a['redirect_to_slug']??'')===$b['slug']){$report[]=['source'=>$row['source_slug'],'status'=>'unchanged'];continue;}
        if(!empty($a['redirect_to_slug'])){$report[]=['source'=>$row['source_slug'],'status'=>'conflicting_redirect'];continue;}
        if(!$apply){$report[]=['source'=>$row['source_slug'],'status'=>'would_apply'];continue;}
        $q=$db->prepare('INSERT INTO seo_page_history(path,old_values,new_values) VALUES(?,?,?)');$q->execute(['/publikaciya/'.$a['slug'].'/',json_encode(['publication_id'=>$a['id'],'redirect_to_slug'=>$a['redirect_to_slug'],'updated_at'=>$a['updated_at']],JSON_UNESCAPED_UNICODE),json_encode(['publication_id'=>$a['id'],'redirect_to_slug'=>$b['slug']],JSON_UNESCAPED_UNICODE)]);
        $q=$db->prepare('UPDATE publications SET redirect_to_slug=?, updated_at=updated_at WHERE id=?');$q->execute([$b['slug'],$a['id']]);$db->commit();
        $report[]=['source'=>$row['source_slug'],'status'=>'applied'];
    } finally {if($db->inTransaction())$db->rollBack();}
}
echo json_encode($report,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

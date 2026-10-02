<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Идемпотентный импорт независимых SEO-полей. Без --apply только проверка. */
require __DIR__ . '/../config/database.php';
$args=getopt('', ['file:', 'apply', 'environment:']);
if(empty($args['file'])) { fwrite(STDERR,"Нужен --file=пакет.json\n");exit(1); }
$isLocal=in_array(parse_url(SITE_URL,PHP_URL_HOST),['localhost','127.0.0.1'],true);
if(isset($args['apply']) && ($args['environment']??'')!==($isLocal?'local':'production')) throw new RuntimeException('Укажите фактическую среду --environment');
$rows=json_decode(file_get_contents($args['file']),true,512,JSON_THROW_ON_ERROR);
$tables=['publications','materials','courses','competitions','olympiads'];
$counts=['applied'=>0,'unchanged'=>0,'missing'=>0,'changed_source'=>0,'conflicting_override'=>0,'would_apply'=>0];
$details=[];
foreach($rows as $row) {
    if(!in_array($row['table']??'', $tables,true) || !preg_match('~^/(?:[a-z0-9-]+/)+$~D',$row['path']??'')) throw new RuntimeException('Некорректная запись пакета');
    $apply=isset($args['apply']);
    if($apply)$db->beginTransaction();
    try {
        $table=$row['table'];$q=$db->prepare("SELECT * FROM `$table` WHERE id=?".($apply?' FOR UPDATE':''));$q->execute([(int)$row['id']]);$source=$q->fetch(PDO::FETCH_ASSOC);
        $status='would_apply';
        if(!$source)$status='missing';
        else {
            $prefix=['courses'=>'kursy','competitions'=>'konkursy','olympiads'=>'olimpiady','materials'=>'material','publications'=>($source['source']??'')==='blog'?'blog':'publikaciya'][$table];
            if($row['path']!=='/'.$prefix.'/'.$source['slug'].'/')$status='changed_source';
            if($table==='publications') { $q=$db->prepare('SELECT full_name FROM users WHERE id=?');$q->execute([$source['user_id']]);$source['public_author']=$q->fetchColumn() ?: ''; }
            foreach($row['expected']??[] as $field=>$value) if((string)($source[$field]??'')!==(string)($value??''))$status='changed_source';
            if(empty($row['expected']) || !hash_equals($row['expected_sha256'],hash('sha256',$source['title']."\n".($source['content']??''))))$status='changed_source';
        }
        if($status!=='would_apply') { $counts[$status]++;$details[]=['path'=>$row['path'],'status'=>$status];continue; }
        $q=$db->prepare('SELECT * FROM seo_page_overrides WHERE path=?'.($apply?' FOR UPDATE':''));$q->execute([$row['path']]);$old=$q->fetch(PDO::FETCH_ASSOC)?:[];
        $changes=[];
        foreach(['meta_title','seo_h1','meta_description'] as $field) {
            if(!isset($row[$field]) || ($old[$field]??null)===$row[$field])continue;
            if(!empty($old[$field])) { $status='conflicting_override';break; }
            $changes[$field]=$row[$field];
        }
        if($status==='conflicting_override') { $counts[$status]++;$details[]=['path'=>$row['path'],'status'=>$status];continue; }
        if(!$changes){$counts['unchanged']++;continue;}
        if(!$apply){$counts['would_apply']++;continue;}
        $new=array_merge($old,['path'=>$row['path']],$changes);
        $q=$db->prepare('INSERT INTO seo_page_history(path,old_values,new_values) VALUES(?,?,?)');$q->execute([$row['path'],$old?json_encode($old,JSON_UNESCAPED_UNICODE):null,json_encode($new,JSON_UNESCAPED_UNICODE)]);
        if(!$old){$q=$db->prepare('INSERT INTO seo_page_overrides(path) VALUES(?)');$q->execute([$row['path']]);}
        $assignments=implode(',',array_map(static fn($field)=>$field.'=?',array_keys($changes)));
        $q=$db->prepare('UPDATE seo_page_overrides SET '.$assignments.' WHERE path=?');$q->execute([...array_values($changes),$row['path']]);
        $db->commit();$counts['applied']++;
    } finally { if($db->inTransaction())$db->rollBack(); }
}
echo json_encode(['summary'=>$counts,'not_applied'=>$details],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT).PHP_EOL;

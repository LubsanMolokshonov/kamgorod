<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
/** Адресный откат записи истории с защитой более новых изменений. */
require __DIR__.'/../config/database.php';
$args=getopt('',['id:','apply','environment:']);
if(empty($args['id']) || !ctype_digit((string)$args['id'])) throw new RuntimeException('Нужен --id записи seo_page_history');
$apply=isset($args['apply']);
$local=in_array(parse_url(SITE_URL,PHP_URL_HOST),['localhost','127.0.0.1'],true);
if($apply && ($args['environment']??'')!==($local?'local':'production')) throw new RuntimeException('Неверная среда');
$db->beginTransaction();
try {
    $q=$db->prepare('SELECT * FROM seo_page_history WHERE id=? FOR UPDATE');$q->execute([(int)$args['id']]);$history=$q->fetch();
    if(!$history)throw new RuntimeException('Запись истории не найдена');
    $q=$db->prepare('SELECT id FROM seo_page_history WHERE path=? AND id>? LIMIT 1');$q->execute([$history['path'],$history['id']]);
    if($q->fetchColumn())throw new RuntimeException('Есть более поздняя правка: сначала рассмотрите её');
    $old=json_decode($history['old_values']??'null',true,512,JSON_THROW_ON_ERROR);
    $new=json_decode($history['new_values'],true,512,JSON_THROW_ON_ERROR);
    if(isset($new['rollback_of']))throw new RuntimeException('Это запись отката; для повторного применения используйте исходный пакет');
    if(isset($new['publication_id'])) {
        $q=$db->prepare('SELECT redirect_to_slug FROM publications WHERE id=? FOR UPDATE');$q->execute([$new['publication_id']]);$current=$q->fetch();
        if(!$current || $current['redirect_to_slug']!==$new['redirect_to_slug'])throw new RuntimeException('Перенаправление изменено после импорта');
        if($apply){$q=$db->prepare('UPDATE publications SET redirect_to_slug=?,updated_at=updated_at WHERE id=?');$q->execute([$old['redirect_to_slug'],$new['publication_id']]);}
    } elseif(isset($new['olympiad_id'])) {
        // Не удаляем сущность, вопросы и документы даже при откате.
        $q=$db->prepare('SELECT id FROM olympiads WHERE id=? AND slug=? FOR UPDATE');$q->execute([$new['olympiad_id'],basename(trim($history['path'],'/'))]);
        if(!$q->fetchColumn())throw new RuntimeException('Восстановленная карточка изменена');
        $q=$db->prepare('SELECT id FROM olympiad_registrations WHERE olympiad_id=? LIMIT 1');$q->execute([$new['olympiad_id']]);
        if($q->fetchColumn())throw new RuntimeException('Есть регистрации: требуется отдельный разбор доступности');
        if($apply){$q=$db->prepare('UPDATE olympiads SET is_active=0,updated_at=updated_at WHERE id=?');$q->execute([$new['olympiad_id']]);}
    } else {
        $fields=['meta_title','seo_h1','meta_description','intro_text','editorial_json','index_catalog'];
        $q=$db->prepare('SELECT * FROM seo_page_overrides WHERE path=? FOR UPDATE');$q->execute([$history['path']]);$current=$q->fetch();
        if(!$current)throw new RuntimeException('SEO-запись отсутствует');
        foreach($fields as $field) if(array_key_exists($field,$new) && (string)($current[$field]??'')!==(string)($new[$field]??''))throw new RuntimeException('Поле изменено после импорта: '.$field);
        if($apply) {
            if($old===null){$q=$db->prepare('DELETE FROM seo_page_overrides WHERE path=?');$q->execute([$history['path']]);}
            else {$q=$db->prepare('UPDATE seo_page_overrides SET '.implode(',',array_map(static fn($f)=>$f.'=?',$fields)).' WHERE path=?');$q->execute([...array_map(static fn($f)=>$old[$f]??($f==='index_catalog'?0:null),$fields),$history['path']]);}
        }
    }
    if($apply) {
        $q=$db->prepare('INSERT INTO seo_page_history(path,old_values,new_values) VALUES(?,?,?)');
        $q->execute([$history['path'],json_encode($new,JSON_UNESCAPED_UNICODE),json_encode(['rollback_of'=>(int)$history['id'],'restored'=>$old],JSON_UNESCAPED_UNICODE)]);
        $db->commit();
    }
    echo json_encode(['id'=>(int)$history['id'],'path'=>$history['path'],'status'=>$apply?'rolled_back':'would_rollback'],JSON_UNESCAPED_UNICODE).PHP_EOL;
} finally {if($db->inTransaction())$db->rollBack();}

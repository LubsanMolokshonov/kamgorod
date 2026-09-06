<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

$action=$argv[1]??'list';
if($action==='list'){
    $rows=$db->query('SELECT channel,chat_id,chat_type,is_enabled,reply_policy,title,last_active_at FROM ai_messenger_chats ORDER BY last_active_at DESC')->fetchAll();
    foreach($rows as $r) echo implode("\t",[$r['channel'],$r['chat_id'],$r['is_enabled']?'ON':'off',$r['reply_policy'],$r['chat_type'],$r['title']??'',$r['last_active_at']??''])."\n";
    exit;
}
if(!in_array($action,['enable','disable'],true)||empty($argv[2])||empty($argv[3])){
    fwrite(STDERR,"Использование: php scripts/manage-messenger-chats.php list | enable|disable telegram|max_bot <chat_id> [questions|mentions|all]\n");exit(2);
}
$channel=$argv[2];$chatId=$argv[3];$policy=$argv[4]??'questions';
if(!in_array($channel,['telegram','max_bot'],true)||!in_array($policy,['questions','mentions','all'],true))throw new InvalidArgumentException('Некорректный канал или policy');
$stmt=$db->prepare('UPDATE ai_messenger_chats SET is_enabled=?,reply_policy=? WHERE channel=? AND chat_id=?');$stmt->execute([$action==='enable'?1:0,$policy,$channel,$chatId]);
$exists=$db->prepare('SELECT 1 FROM ai_messenger_chats WHERE channel=? AND chat_id=?');$exists->execute([$channel,$chatId]);
echo $exists->fetchColumn()?"Готово.\n":"Чат не найден. Сначала бот должен получить из него событие.\n";

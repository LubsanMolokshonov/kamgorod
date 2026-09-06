<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('TELEGRAM_BOT_USERNAME','FgosHelperBot');
define('MAX_BOT_USERNAME','FgosMaxBot');
require_once __DIR__.'/../ai-consultant/src/MessengerMessagePolicy.php';
require_once __DIR__.'/../ai-consultant/src/MessengerWebhookParser.php';

function check(bool $value,string $label):void{if(!$value){fwrite(STDERR,"FAIL: $label\n");exit(1);}echo "OK: $label\n";}

check(MessengerMessagePolicy::shouldAnswer(['text'=>'Здравствуйте','chat_type'=>'private','from_bot'=>false]),'личный чат');
check(!MessengerMessagePolicy::shouldAnswer(['text'=>'Сегодня хорошая погода','chat_type'=>'group','from_bot'=>false]),'обычная групповая беседа');
check(MessengerMessagePolicy::shouldAnswer(['text'=>'Когда следующий вебинар?','chat_type'=>'group','from_bot'=>false]),'вопрос в группе');
check(MessengerMessagePolicy::shouldAnswer(['text'=>'Подскажите стоимость курса','chat_type'=>'group','from_bot'=>false]),'обращение без вопросительного знака');
check(!MessengerMessagePolicy::shouldAnswer(['text'=>'Когда вебинар?','chat_type'=>'group','from_bot'=>true]),'игнор другого бота');
check(MessengerMessagePolicy::isPersonalRequest('Я оплатила заказ, но мне не пришёл сертификат'),'персональный вопрос');

$tg=MessengerWebhookParser::telegram(['update_id'=>101,'message'=>['message_id'=>7,'text'=>'@FgosHelperBot когда вебинар?','chat'=>['id'=>-1001,'type'=>'supergroup','title'=>'Педагоги'],'from'=>['id'=>55,'first_name'=>'Анна','is_bot'=>false]]]);
check($tg!==null&&$tg['chat_id']==='-1001'&&$tg['mentioned_bot']===true&&$tg['chat_type']==='supergroup','Telegram parser');

$max=MessengerWebhookParser::max(['update_type'=>'message_created','timestamp'=>1,'message'=>['sender'=>['user_id'=>77,'first_name'=>'Ирина','is_bot'=>false],'recipient'=>['chat_id'=>99,'chat_type'=>'chat','title'=>'Учителя'],'body'=>['mid'=>'mid.123','text'=>'Подскажите курс для логопеда?']]]);
check($max!==null&&$max['provider_message_id']==='mid.123'&&$max['chat_id']==='99'&&$max['chat_type']==='group','MAX parser');

$duplicateKeyA=$tg['provider_update_id'];
$duplicateKeyB=MessengerWebhookParser::telegram(['update_id'=>101,'message'=>['message_id'=>7,'text'=>'повтор','chat'=>['id'=>-1001,'type'=>'supergroup'],'from'=>['id'=>55]]])['provider_update_id'];
check($duplicateKeyA===$duplicateKeyB,'стабильный ключ дедупликации');
echo "Все тесты пройдены.\n";

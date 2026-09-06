<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

$run=in_array('--run',$argv,true);
$question='Когда ближайший вебинар и о чём он?';
foreach($argv as $arg)if(str_starts_with($arg,'--question='))$question=substr($arg,11);
echo 'model='.MESSENGER_OPENROUTER_MODEL.' enabled='.(MESSENGER_AI_ACTIVE?'yes':'no')."\n";
if(!$run){echo "DRY-RUN. Полная проверка делает один вызов OpenRouter; добавьте --run [--question=...].\n";exit;}
$service=new MessengerAIService($db);
$result=$service->answer(['channel'=>'telegram','chat_id'=>'diagnostic','user_id'=>'diagnostic','chat_type'=>'private','provider_message_id'=>'diagnostic-'.time(),'message_text'=>$question]);
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";

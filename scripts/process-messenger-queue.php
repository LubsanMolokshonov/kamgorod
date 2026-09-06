<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

$args = array_slice($argv, 1);
$send = in_array('--send', $args, true);
$loop = in_array('--loop', $args, true);
if (!$send) {
    $counts=$db->query("SELECT status,COUNT(*) cnt FROM ai_messenger_events GROUP BY status ORDER BY status")->fetchAll();
    echo "DRY-RUN: сообщения не обрабатываются. Для запуска добавьте --send.\n";
    foreach($counts as $row) echo $row['status'].': '.$row['cnt']."\n";
    exit(0);
}
if (!MESSENGER_AI_ACTIVE) {
    if (!$loop) { fwrite(STDERR,"MESSENGER_AI_ENABLED=false — обработка не запущена.\n"); exit(2); }
    fwrite(STDOUT,"MESSENGER_AI_ENABLED=false — worker ожидает перезапуска после включения.\n");
    while (true) sleep(60);
}

$lock=fopen('/tmp/fgos-messenger-worker.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Другой messenger-worker уже запущен.\n");exit(0);}
$worker=new MessengerWorker($db);
$stop=false;
if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGTERM,function()use(&$stop){$stop=true;});pcntl_signal(SIGINT,function()use(&$stop){$stop=true;});}

do {
    try { $processed=$worker->processOne(); }
    catch(Throwable $e){error_log('[messenger-worker] '.$e->getMessage());$processed=false;if(!$loop)throw $e;}
    if(!$loop) break;
    if(!$processed) usleep(1000000);
} while(!$stop);

flock($lock,LOCK_UN);fclose($lock);

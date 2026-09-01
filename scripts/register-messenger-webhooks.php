<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

$apply=in_array('--apply',$argv,true);
$site=rtrim((string)PUBLIC_SITE_URL,'/');
$telegram=new TelegramBotClient(); $max=new MaxBotClient();

echo $apply?"APPLY: регистрация webhook\n":"DRY-RUN: текущий статус и план. Для изменения добавьте --apply.\n";

if(TELEGRAM_BOT_TOKEN==='') echo "Telegram: TELEGRAM_BOT_TOKEN не задан\n";
else {
    $me=$telegram->request('getMe'); $info=$telegram->request('getWebhookInfo');
    echo 'Telegram bot: @'.($me['response']?((json_decode($me['response'],true)['result']['username']??'unknown')):'unknown')."\n";
    echo 'Telegram webhook: '.((json_decode((string)($info['response']??''),true)['result']['url']??'(не задан)'))."\n";
    if($apply){
        if(TELEGRAM_WEBHOOK_SECRET==='') throw new RuntimeException('TELEGRAM_WEBHOOK_SECRET не задан');
        $r=$telegram->request('setWebhook',['url'=>$site.'/api/webhook/telegram-ai.php','secret_token'=>TELEGRAM_WEBHOOK_SECRET,'allowed_updates'=>['message'],'drop_pending_updates'=>false]);
        echo 'Telegram setWebhook: '.(!empty($r['success'])?'OK':'ERROR '.$r['error'])."\n";
    }
}

if(MAX_BOT_TOKEN==='') echo "MAX: MAX_BOT_TOKEN не задан\n";
else {
    $subs=$max->request('GET','/subscriptions');
    echo 'MAX subscriptions: '.(!empty($subs['success'])?'доступны':'ERROR '.$subs['error'])."\n";
    if($apply){
        if(MAX_BOT_WEBHOOK_SECRET==='') throw new RuntimeException('MAX_BOT_WEBHOOK_SECRET не задан');
        $targetUrl=$site.'/api/webhook/max-bot.php';
        $existing=false;
        foreach(($subs['data']['subscriptions']??[]) as $subscription){if(($subscription['url']??'')===$targetUrl){$existing=true;break;}}
        if($existing) echo "MAX subscribe: уже зарегистрирован (повтор не создавался)\n";
        else {
            $r=$max->request('POST','/subscriptions',['url'=>$targetUrl,'update_types'=>['message_created','bot_added','bot_started'],'secret'=>MAX_BOT_WEBHOOK_SECRET]);
            echo 'MAX subscribe: '.(!empty($r['success'])?'OK':'ERROR '.$r['error'])."\n";
        }
    }
}

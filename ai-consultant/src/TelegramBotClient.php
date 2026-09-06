<?php
declare(strict_types=1);

class TelegramBotClient
{
    private string $token;
    public function __construct(?string $token = null) { $this->token = $token ?? (string)TELEGRAM_BOT_TOKEN; }

    public function send(array $event, string $text): array
    {
        if ($this->token === '') return ['success'=>false,'retryable'=>false,'error'=>'TELEGRAM_BOT_TOKEN не задан'];
        $payload = ['chat_id'=>(string)$event['chat_id'],'text'=>mb_substr($text,0,4096),'disable_web_page_preview'=>false];
        if (!empty($event['provider_message_id'])) $payload['reply_parameters'] = ['message_id'=>(int)$event['provider_message_id'],'allow_sending_without_reply'=>true];
        return $this->request('sendMessage', $payload);
    }

    public function request(string $method, array $payload = []): array
    {
        $ch=curl_init('https://api.telegram.org/bot'.$this->token.'/'.$method);
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        $json=is_string($raw)?json_decode($raw,true):null; $ok=$code===200&&!empty($json['ok']);
        return ['success'=>$ok,'retryable'=>$code===429||$code>=500||$raw===false,'retry_after'=>(int)($json['parameters']['retry_after']??0),'message_id'=>$json['result']['message_id']??null,'http_code'=>$code,'response'=>$raw,'error'=>$ok?null:($err?:($json['description']??'Telegram API error'))];
    }
}

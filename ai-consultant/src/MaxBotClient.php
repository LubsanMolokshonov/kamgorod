<?php
declare(strict_types=1);

class MaxBotClient
{
    private string $token;
    public function __construct(?string $token = null) { $this->token = $token ?? (string)MAX_BOT_TOKEN; }

    public function send(array $event, string $text): array
    {
        if ($this->token === '') return ['success'=>false,'retryable'=>false,'error'=>'MAX_BOT_TOKEN не задан'];
        $target = $event['chat_type']==='private' && !empty($event['user_id']) ? ['user_id'=>$event['user_id']] : ['chat_id'=>$event['chat_id']];
        $url = MAX_BOT_API_URL . '/messages?' . http_build_query($target);
        $payload=['text'=>mb_substr($text,0,4000),'notify'=>true];
        if (!empty($event['provider_message_id'])) $payload['link']=['type'=>'reply','mid'=>(string)$event['provider_message_id']];
        $ch=curl_init($url); $retryHeader=0;
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Authorization: '.$this->token,'Content-Type: application/json'],CURLOPT_POSTFIELDS=>json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5,
            CURLOPT_HEADERFUNCTION=>static function($curl,string $header)use(&$retryHeader):int{if(stripos($header,'Retry-After:')===0)$retryHeader=(int)trim(substr($header,12));return strlen($header);}]);
        $raw=curl_exec($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); $err=curl_error($ch); curl_close($ch);
        $json=is_string($raw)?json_decode($raw,true):null; $ok=$code>=200&&$code<300&&is_array($json);
        $apiError = is_array($json) && is_string($json['message'] ?? null)
            ? $json['message']
            : (is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : 'MAX API error');
        return ['success'=>$ok,'retryable'=>$code===429||$code>=500||$raw===false,'retry_after'=>$retryHeader,'message_id'=>$json['message']['body']['mid']??$json['message']['id']??null,'http_code'=>$code,'response'=>$raw,'error'=>$ok?null:($err?:$apiError)];
    }

    public function request(string $method, string $path, ?array $payload = null): array
    {
        $ch=curl_init(MAX_BOT_API_URL.$path); $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Authorization: '.$this->token,'Content-Type: application/json'],CURLOPT_TIMEOUT=>15,CURLOPT_CONNECTTIMEOUT=>5];
        if($payload!==null)$opts[CURLOPT_POSTFIELDS]=json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); curl_setopt_array($ch,$opts);
        $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);curl_close($ch);$json=is_string($raw)?json_decode($raw,true):null;
        $apiError = is_array($json) && is_string($json['message'] ?? null)
            ? $json['message']
            : (is_array($json) && is_string($json['error'] ?? null) ? $json['error'] : null);
        return ['success'=>$code>=200&&$code<300,'http_code'=>$code,'data'=>$json,'response'=>$raw,'error'=>$err?:$apiError];
    }
}

<?php
declare(strict_types=1);

class MessengerWebhookParser
{
    public static function telegram(array $update): ?array
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) return null;
        $chat = is_array($message['chat'] ?? null) ? $message['chat'] : [];
        $from = is_array($message['from'] ?? null) ? $message['from'] : [];
        $text = trim((string)($message['text'] ?? $message['caption'] ?? ''));
        $username = defined('TELEGRAM_BOT_USERNAME') ? TELEGRAM_BOT_USERNAME : '';
        $replyFrom = $message['reply_to_message']['from'] ?? [];
        $replyToBot = is_array($replyFrom) && !empty($replyFrom['is_bot']) && ($username === '' || strcasecmp((string)($replyFrom['username'] ?? ''), $username) === 0);
        $mentioned = $username !== '' && preg_match('/@' . preg_quote($username, '/') . '(?![\w])/ui', $text);
        $title = trim((string)($chat['title'] ?? trim((string)($chat['first_name'] ?? '') . ' ' . (string)($chat['last_name'] ?? ''))));
        return [
            'channel'=>'telegram','provider_update_id'=>(string)($update['update_id'] ?? hash('sha256',json_encode($update))),
            'provider_message_id'=>(string)($message['message_id'] ?? ''),'chat_id'=>(string)($chat['id'] ?? ''),
            'user_id'=>isset($from['id'])?(string)$from['id']:null,'user_name'=>trim((string)($from['first_name']??'').' '.(string)($from['last_name']??''))?:($from['username']??null),
            'chat_type'=>self::chatType((string)($chat['type'] ?? 'unknown')),'chat_title'=>$title?:null,'text'=>$text,
            'reply_to_message_id'=>isset($message['reply_to_message']['message_id'])?(string)$message['reply_to_message']['message_id']:null,
            'reply_to_bot'=>$replyToBot,'mentioned_bot'=>(bool)$mentioned,'from_bot'=>!empty($from['is_bot']),'raw_payload'=>$update,
        ];
    }

    public static function max(array $update): ?array
    {
        if (($update['update_type'] ?? '') !== 'message_created' || !is_array($update['message'] ?? null)) return null;
        $message=$update['message']; $body=is_array($message['body']??null)?$message['body']:[];
        $sender=is_array($message['sender']??null)?$message['sender']:[]; $recipient=is_array($message['recipient']??null)?$message['recipient']:[];
        $text=trim((string)($body['text']??'')); $mid=(string)($body['mid']??$message['message_id']??'');
        $rawType=(string)($recipient['chat_type']??'');
        $chatType=match($rawType){'dialog','private'=>'private','chat','group'=>'group','channel'=>'channel',default=>(isset($recipient['chat_id'])?'group':'private')};
        $chatId=(string)($recipient['chat_id']??($sender['user_id']??$recipient['user_id']??''));
        $username=defined('MAX_BOT_USERNAME')?MAX_BOT_USERNAME:'';
        $link=is_array($message['link']??null)?$message['link']:[];
        $linkedSender=$link['sender']??$link['message']['sender']??[];
        $replyToBot=($link['type']??'')==='reply' && is_array($linkedSender) && !empty($linkedSender['is_bot']) && ($username===''||strcasecmp((string)($linkedSender['username']??''),$username)===0);
        return [
            'channel'=>'max_bot','provider_update_id'=>$mid!==''?'message_created:'.$mid:'message_created:'.hash('sha256',json_encode($update)),
            'provider_message_id'=>$mid,'chat_id'=>$chatId,'user_id'=>isset($sender['user_id'])?(string)$sender['user_id']:null,
            'user_name'=>trim((string)($sender['first_name']??'').' '.(string)($sender['last_name']??''))?:($sender['name']??$sender['username']??null),
            'chat_type'=>$chatType,'chat_title'=>$recipient['title']??null,'text'=>$text,
            'reply_to_message_id'=>(string)($link['message']['body']['mid']??$link['mid']??''),'reply_to_bot'=>$replyToBot,
            'mentioned_bot'=>$username!==''&&(bool)preg_match('/@'.preg_quote($username,'/').'(?![\w])/ui',$text),
            'from_bot'=>!empty($sender['is_bot']),'raw_payload'=>$update,
        ];
    }

    private static function chatType(string $type): string
    {
        return in_array($type,['private','group','supergroup','channel'],true)?$type:'unknown';
    }
}

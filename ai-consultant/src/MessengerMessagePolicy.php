<?php
declare(strict_types=1);

/** Детерминированный фильтр: не отправляет обычную групповую переписку в ИИ. */
class MessengerMessagePolicy
{
    public static function shouldAnswer(array $message, string $policy = 'questions'): bool
    {
        $text = trim((string)($message['text'] ?? ''));
        if ($text === '' || !empty($message['from_bot'])) {
            return false;
        }

        if (($message['chat_type'] ?? 'unknown') === 'private' || $policy === 'all') {
            return mb_strlen($text) >= 2;
        }

        if (!empty($message['mentioned_bot']) || !empty($message['reply_to_bot']) || preg_match('/^\/ask(?:@\w+)?(?:\s|$)/ui', $text)) {
            return true;
        }
        if ($policy === 'mentions') {
            return false;
        }

        if (str_contains($text, '?')) {
            return true;
        }

        return (bool)preg_match(
            '/(?:^|[.!]\s*)(?:подскажите|скажите|помогите|объясните|расскажите|уточните|хочу\s+узнать|интересует|как\s|когда\s|где\s|сколько\s|можно\s+ли|нужно\s+ли|есть\s+ли|что\s+нужно|не\s+могу|не\s+приш[её]л|не\s+открывается|не\s+скачивается)/ui',
            $text
        );
    }

    public static function isPersonalRequest(string $text): bool
    {
        return (bool)preg_match(
            '/(?:мой|моя|мо[её]|мне|у\s+меня|я\s+оплатил|оплатила|заказ|плат[её]ж|чек|возврат|доступ|пароль|не\s+приш[её]л|не\s+скачивается|диплом|сертификат).{0,50}(?:мой|мне|заказ|оплат|доступ|документ|диплом|сертификат)|(?:я\s+оплатил|я\s+оплатила|верните\s+деньги)/ui',
            $text
        );
    }
}

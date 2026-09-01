<?php
declare(strict_types=1);

class MessengerWorker
{
    private MessengerQueue $queue;
    private MessengerAIService $ai;
    private AlertService $alerts;
    private TelegramBotClient $telegram;
    private MaxBotClient $max;

    public function __construct(PDO $pdo)
    {
        $this->queue = new MessengerQueue($pdo);
        $this->ai = new MessengerAIService($pdo);
        $this->alerts = new AlertService($pdo);
        $this->telegram = new TelegramBotClient();
        $this->max = new MaxBotClient();
    }

    public function processOne(): bool
    {
        $event = $this->queue->claim();
        if (!$event) return false;
        $id = (int)$event['id'];

        $limit = $this->queue->overLimits($event);
        if ($limit === 'chat_rate_limit') {
            $this->queue->defer($id, $limit, 15);
            return true;
        }
        if ($limit === 'daily_cap' || $limit === 'daily_token_budget') {
            $this->fallbackAndEscalate($event, 'Достигнут суточный лимит автоответчика: ' . $limit, 'technical');
            return true;
        }

        // Если Gemini уже подготовил ответ, а транспорт временно упал, повторяем только
        // отправку: не тратим токены повторно и не дублируем историю диалога.
        if (!empty($event['response_text'])) {
            $this->deliverPrepared($event, (string)$event['response_text'], $event['alert_id'] ? (int)$event['alert_id'] : null, [
                'tokens_in'=>(int)($event['tokens_in'] ?? 0), 'tokens_out'=>(int)($event['tokens_out'] ?? 0), 'model'=>(string)($event['model'] ?? ''),
            ]);
            return true;
        }

        try {
            $answer = $this->ai->answer($event);
        } catch (Throwable $e) {
            ai_log('MESSENGER', 'Gemini failed', ['event_id'=>$id,'error'=>$e->getMessage()]);
            $this->fallbackAndEscalate($event, 'OpenRouter/Gemini недоступен: ' . $e->getMessage(), 'technical');
            return true;
        }

        $alertId = null;
        if ($answer['needs_handoff']) {
            try {
                $alertId = $this->alerts->createFromMessenger($event, ['category'=>$this->alertCategory($answer['category']),'summary'=>'ИИ передал диалог менеджеру. Ответ: '.$answer['answer']]);
            } catch (Throwable $e) {
                ai_log('MESSENGER', 'Alert create failed', ['event_id'=>$id,'error'=>$e->getMessage()]);
            }
        }

        $this->queue->savePrepared($id, $answer['answer'], $alertId, $answer);
        $this->deliverPrepared($event, $answer['answer'], $alertId, $answer);
        return true;
    }

    private function deliverPrepared(array $event, string $text, ?int $alertId, array $answer): void
    {
        $id = (int)$event['id'];
        $sent = $this->send($event, $text);
        if (!empty($sent['success'])) {
            $this->queue->finish($id, $alertId ? 'escalated' : 'sent', [
                'response_message_id'=>$sent['message_id']??null,'alert_id'=>$alertId,
                'tokens_in'=>$answer['tokens_in'] ?? 0,'tokens_out'=>$answer['tokens_out'] ?? 0,'model'=>$answer['model'] ?? null,
            ]);
        } elseif (!empty($sent['retryable']) && (int)$event['attempts'] < 3) {
            $delay=max(5,(int)($sent['retry_after']??0),5*(2**((int)$event['attempts']-1)));
            $this->queue->defer($id,(string)($sent['error']??'send_failed'),$delay);
        } else {
            if (!$alertId) {
                try { $alertId=$this->alerts->createFromMessenger($event,['category'=>'technical','summary'=>'Автоответ сформирован, но не доставлен: '.($sent['error']??'ошибка API')]); } catch(Throwable $ignored) {}
            }
            $this->queue->finish($id,'failed',['alert_id'=>$alertId,'tokens_in'=>$answer['tokens_in'] ?? 0,'tokens_out'=>$answer['tokens_out'] ?? 0,'model'=>$answer['model'] ?? null,'error'=>$sent['error']??'send_failed']);
        }
    }

    private function fallbackAndEscalate(array $event, string $internalError, string $category): void
    {
        $message='Сейчас я не могу надёжно проверить данные и не хочу отвечать наугад. Я передал вопрос менеджеру — специалист уточнит информацию и поможет.';
        $alertId=null;
        try { $alertId=$this->alerts->createFromMessenger($event,['category'=>$category,'summary'=>$internalError]); } catch(Throwable $e) { $internalError.='; alert: '.$e->getMessage(); }
        $this->queue->savePrepared((int)$event['id'],$message,$alertId,['model'=>'fallback']);
        $sent=$this->send($event,$message);
        if (empty($sent['success']) && !empty($sent['retryable']) && (int)$event['attempts']<3) {
            $this->queue->defer((int)$event['id'],$internalError.'; send: '.($sent['error']??''),max(5,(int)($sent['retry_after']??0)));
            return;
        }
        $this->queue->finish((int)$event['id'],$alertId?'escalated':'failed',['alert_id'=>$alertId,'response_message_id'=>$sent['message_id']??null,'model'=>'fallback','error'=>empty($sent['success'])?$internalError.'; send: '.($sent['error']??''):$internalError]);
    }

    private function send(array $event, string $text): array
    {
        if ($event['channel'] === 'telegram') return $this->telegram->send($event,$text);
        $delayMicros = $this->queue->reserveMaxSendSlot((string)$event['chat_id']);
        if ($delayMicros > 0) usleep($delayMicros);
        return $this->max->send($event,$text);
    }
    private function alertCategory(string $category): string { return $category==='product'?'content':(in_array($category,['payment','technical','content','access','other'],true)?$category:'other'); }
}

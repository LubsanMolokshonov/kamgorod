<?php
declare(strict_types=1);

/** Источник только для чтения; реализации не должны менять флаги или получать вложения. */
interface ServiceMailHistoryReader
{
    public function folders(): array;
    public function candidates(array $folder, string $email): array;
    public function message(array $candidate): array;
    public function close(): void;
}

/** Дополнительный, недоверенный контекст поддержки. Никаких записей в БД и отправок. */
final class ServiceMailHistory
{
    public const CANDIDATE_LIMIT = 200;
    public const MESSAGE_LIMIT = 50;
    private Closure $clock;

    public function __construct(private ServiceMailHistoryReader $reader, private bool $enabled, ?Closure $clock = null)
    {
        $this->clock = $clock ?? static fn(): float => microtime(true);
    }

    public function lookup(string $email): array
    {
        $result = ['status' => 'disabled', 'messages' => [], 'no_matches' => null,
            'complete' => false, 'reasons' => [], 'folders_searched' => 0, 'candidates_read' => 0,
            'context_is_untrusted' => true];
        if (!$this->enabled) {
            return $result;
        }
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n]/', $email)) {
            $result['status'] = 'unavailable';
            $result['reasons'][] = 'invalid_customer_email';
            return $result;
        }
        $deadline = ($this->clock)() + 60;
        $messages = [];
        $reasons = [];
        $searched = false;
        try {
            $candidates = [];
            foreach ($this->reader->folders() as $folder) {
                $this->checkDeadline($deadline);
                if (self::excludedFolder($folder)) {
                    continue;
                }
                try {
                    array_push($candidates, ...$this->reader->candidates($folder, $email));
                    $result['folders_searched']++;
                    $searched = true;
                } catch (Throwable $e) {
                    if ($e->getMessage() === 'history_timeout') { throw $e; }
                    $reasons[] = 'folder_search_failed';
                }
            }
            // INTERNALDATE, а не UID: UID разных папок несопоставимы.
            usort($candidates, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
            if (count($candidates) >= self::CANDIDATE_LIMIT) {
                $reasons[] = 'candidate_limit';
            }
            foreach (array_slice($candidates, 0, self::CANDIDATE_LIMIT) as $candidate) {
                $this->checkDeadline($deadline);
                if (($candidate['address_matches'] ?? true) === false) { continue; }
                try {
                    $message = $this->reader->message($candidate);
                    $result['candidates_read']++;
                    $from = array_map('strtolower', $message['from']);
                    $recipients = array_map('strtolower', array_merge($message['to'], $message['cc']));
                    if (!in_array($email, $from, true) && !in_array($email, $recipients, true)) {
                        continue;
                    }
                    $message['direction'] = in_array($email, $from, true) ? 'inbound' : 'outbound';
                    if (!empty($message['truncated'])) { $reasons[] = 'body_truncated'; }
                    $key = $message['message_id'] ?: hash('sha256', json_encode([
                        $message['from'], $message['to'], $message['date'], $message['subject'], $message['text'],
                    ]));
                    $messages[$key] = $message;
                } catch (Throwable $e) {
                    if ($e->getMessage() === 'history_timeout') { throw $e; }
                    $reasons[] = 'message_read_failed';
                }
            }
        } catch (Throwable $e) {
            $reasons[] = $e->getMessage() === 'history_timeout' ? 'timeout' : 'imap_unavailable';
        } finally {
            try { $this->reader->close(); } catch (Throwable $e) { $reasons[] = 'disconnect_failed'; }
        }
        $accepted = self::projectMessages(array_values($messages));
        usort($accepted, static fn(array $a, array $b): int => $b['timestamp'] <=> $a['timestamp']);
        if (count($accepted) >= self::MESSAGE_LIMIT) { $reasons[] = 'message_limit'; }
        $result['messages'] = array_slice($accepted, 0, self::MESSAGE_LIMIT);
        $result['reasons'] = array_values(array_unique($reasons));
        $result['status'] = !$searched ? 'unavailable' : ($reasons ? 'partial' : 'ok');
        $result['complete'] = $result['status'] === 'ok';
        $result['no_matches'] = $result['complete'] ? !$accepted : null;
        return $result;
    }

    private function checkDeadline(float $deadline): void
    {
        if (($this->clock)() >= $deadline) { throw new RuntimeException('history_timeout'); }
    }

    public static function excludedFolder(array $folder): bool
    {
        $flags = array_map('strtolower', $folder['flags'] ?? []);
        if (array_intersect($flags, ['\\noselect', '\\junk', '\\trash', '\\drafts'])) { return true; }
        return (bool)preg_match('~(?:^|[/ .])(?:spam|junk|trash|drafts?|deleted(?: messages| items)?|спам|корзина|черновики|удаленные|удалённые)(?:$|[/.])~ui', $folder['name']);
    }

    /** Только потомки подтверждённого письма: общий непроверенный предок не связывает проекты. */
    public static function projectMessages(array $messages): array
    {
        $accepted = [];
        $ids = [];
        foreach ($messages as $i => $message) {
            $haystack = $message['subject'] . "\n" . $message['text'];
            if (preg_match('~(?<![\pL\pN_.-])fgos\.pro(?![\pL\pN_.-])|(?<![\pL\pN])фгос[\s\p{Pd}]*практикум(?![\pL\pN])~ui', $haystack)) {
                $message['project_evidence'] = ['type' => 'marker'];
                $accepted[$i] = $message;
                if ($message['message_id'] !== '') { $ids[$message['message_id']] = true; }
            }
        }
        do {
            $changed = false;
            foreach ($messages as $i => $message) {
                if (isset($accepted[$i])) { continue; }
                $links = array_merge($message['in_reply_to'], $message['references']);
                foreach ($links as $id) {
                    if (isset($ids[$id])) {
                        $message['project_evidence'] = ['type' => 'thread', 'parent_message_id' => $id];
                        $accepted[$i] = $message;
                        if ($message['message_id'] !== '') { $ids[$message['message_id']] = true; }
                        $changed = true;
                        break;
                    }
                }
            }
        } while ($changed);
        return array_values($accepted);
    }
}

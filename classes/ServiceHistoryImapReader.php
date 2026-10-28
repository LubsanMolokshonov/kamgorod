<?php
declare(strict_types=1);

require_once __DIR__ . '/ServiceMailHistory.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Header;

/** EXAMINE + BODY.PEEK: без STORE/SELECT, полных RFC822 и загрузки вложений. */
final class ServiceHistoryImapReader implements ServiceMailHistoryReader
{
    private ?object $client = null;
    private float $deadline;
    private string $activeFolder = '';

    public function __construct(private array $settings)
    {
        $this->deadline = microtime(true) + 60;
    }

    public static function settings(): array
    {
        $get = static fn(string $key, mixed $default): mixed => defined($key) ? constant($key) : ($_ENV[$key] ?? $default);
        return [
            'enabled' => filter_var($get('SERVICE_HISTORY_IMAP_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
            'host' => $get('SERVICE_HISTORY_IMAP_HOST', 'imap.mail.ru'),
            'port' => (int)$get('SERVICE_HISTORY_IMAP_PORT', 993),
            'username' => $get('SERVICE_HISTORY_IMAP_USERNAME', ''),
            'password' => $get('SERVICE_HISTORY_IMAP_PASSWORD', ''),
        ];
    }

    private function connection(): object
    {
        $remaining = (int)ceil($this->deadline - microtime(true));
        if ($remaining <= 0) { throw new RuntimeException('history_timeout'); }
        if ($this->client === null) {
            if ($this->settings['username'] === '' || $this->settings['password'] === '') {
                throw new RuntimeException('history_credentials_missing');
            }
            $this->client = (new ClientManager())->make([
                'host' => $this->settings['host'], 'port' => $this->settings['port'],
                'username' => $this->settings['username'], 'password' => $this->settings['password'],
                'encryption' => 'ssl', 'validate_cert' => true, 'protocol' => 'imap',
                'authentication' => null, 'timeout' => min(10, $remaining),
            ]);
            $this->client->connect();
        }
        $connection = $this->client->getConnection();
        $connection->setConnectionTimeout(min(10, $remaining));
        if (is_resource($connection->stream ?? null)) { stream_set_timeout($connection->stream, min(10, $remaining)); }
        return $connection;
    }

    public function folders(): array
    {
        $items = $this->connection()->folders('', '*')->validatedData();
        $folders = [];
        foreach ($items as $path => $item) {
            $folder = new Folder($this->client, $path, $item['delimiter'], $item['flags']);
            $folders[] = ['path' => $path, 'name' => $folder->full_name, 'flags' => $item['flags']];
        }
        return $folders;
    }

    private function examine(string $path): void
    {
        if ($this->activeFolder !== $path) {
            $this->connection()->examineFolder($path)->validatedData();
            $this->activeFolder = $path;
        }
    }

    public function candidates(array $folder, string $email): array
    {
        $this->examine($folder['path']);
        $quoted = $this->connection()->escapeString($email);
        // IMAP SEARCH ищет подстроку. Точное совпадение проверяется после разбора заголовков.
        $uids = $this->connection()->search(['UNDELETED OR FROM ' . $quoted . ' OR TO ' . $quoted . ' CC ' . $quoted])->validatedData();
        rsort($uids, SORT_NUMERIC);
        if (!$uids) { return []; }
        // Не грузим тела: получаем только дату доставки и заголовки последних кандидатов папки.
        $rows = $this->connection()->fetch(['INTERNALDATE', 'BODY.PEEK[HEADER]<0.65536>'], array_slice($uids, 0, 200))->validatedData();
        $result = [];
        foreach ($rows as $uid => $row) {
            $raw = self::bodyValue($row);
            $header = new Header($raw, $this->client->getConfig());
            $addresses = [];
            foreach (['from', 'to', 'cc'] as $field) {
                foreach ($header->get($field)->toArray() as $address) { $addresses[] = strtolower((string)$address->mail); }
            }
            $result[] = ['folder' => $folder['path'], 'folder_name' => $folder['name'], 'uid' => (int)$uid,
                'timestamp' => strtotime((string)($row['INTERNALDATE'] ?? '')) ?: 0, 'header' => $raw,
                'address_matches' => in_array(strtolower($email), $addresses, true),
                'header_truncated' => strlen($raw) >= 65536];
        }
        // Число результатов сохраняет сигнал неполноты при отсечении UID.
        if (count($uids) >= 200 && count($result) < 200) {
            throw new RuntimeException('history_candidate_headers_missing');
        }
        return $result;
    }

    public function message(array $candidate): array
    {
        $this->examine($candidate['folder']);
        $header = new Header($candidate['header'], $this->client->getConfig());
        $addresses = static function (string $name) use ($header): array {
            return array_values(array_filter(array_map(static fn($address): string => strtolower((string)$address->mail), $header->get($name)->toArray())));
        };
        $response = $this->connection()->requestAndResponse('UID FETCH', [(string)(int)$candidate['uid'], '(BODYSTRUCTURE)'], true);
        $response->validate();
        // Webklex 6.x неверно токенизирует соседние списки Mail.ru: ("utf-8") NIL.
        // Разбираем оригинальный ответ; транспорт, TLS и получение частей остаются Webklex.
        $parts = self::textParts(self::parseStructureResponse($response->getResponse()));
        $plain = array_values(array_filter($parts, static fn(array $part): bool => $part['type'] === 'plain'));
        $parts = $plain ?: $parts;
        $texts = [];
        $truncated = $candidate['header_truncated'];
        foreach (array_slice($parts, 0, 8) as $part) {
            $row = $this->connection()->fetch(['BODY.PEEK[' . $part['section'] . ']<0.131072>', 'UID'], [$candidate['uid']])->validatedData();
            $raw = self::bodyValue($row[$candidate['uid']] ?? []);
            $truncated = $truncated || strlen($raw) >= 131072;
            $texts[] = self::decodeText($raw, $part['encoding'], $part['charset'], $part['type'] === 'html');
        }
        $text = trim(implode("\n", $texts));
        $truncated = $truncated || count($parts) > 8 || mb_strlen($text) > 12000;
        return [
            'message_id' => trim((string)$header->get('message_id'), '<> '),
            'in_reply_to' => self::ids($candidate['header'], 'In-Reply-To'),
            'references' => self::ids($candidate['header'], 'References'),
            'date' => $candidate['timestamp'] ? gmdate('c', $candidate['timestamp']) : null,
            'timestamp' => $candidate['timestamp'], 'from' => $addresses('from'), 'to' => $addresses('to'),
            'cc' => $addresses('cc'), 'subject' => iconv_mime_decode((string)$header->get('subject'), ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8') ?: (string)$header->get('subject'),
            'text' => mb_substr($text, 0, 12000), 'truncated' => $truncated,
            'source' => ['mailbox' => $this->settings['username'], 'folder' => $candidate['folder_name'], 'uid' => $candidate['uid']],
        ];
    }

    private static function bodyValue(array $row): string
    {
        foreach ($row as $key => $value) {
            if (str_starts_with(strtoupper((string)$key), 'BODY[')) { return (string)$value; }
        }
        throw new RuntimeException('history_body_missing');
    }

    private static function ids(string $raw, string $field): array
    {
        $raw = preg_replace('/\r?\n[\t ]+/', ' ', $raw);
        if (!preg_match('/^' . preg_quote($field, '/') . ':([^\r\n]*)/mi', $raw, $match)) { return []; }
        preg_match_all('/<([^<>\s]+)>/', $match[1], $ids);
        return array_values(array_unique($ids[1]));
    }

    public static function parseStructureResponse(array $lines): array
    {
        $raw = implode('', $lines);
        if (strlen($raw) > 1048576 || !preg_match('/\bBODYSTRUCTURE\s+/i', $raw, $match, PREG_OFFSET_CAPTURE)) {
            throw new RuntimeException('history_structure_invalid');
        }
        $position = $match[0][1] + strlen($match[0][0]);
        $node = self::readImapValue($raw, $position, 0);
        if (!is_array($node)) { throw new RuntimeException('history_structure_invalid'); }
        return $node;
    }

    /** RFC 3501: списки, quoted-string с escape, NIL, atom и literal с длиной в байтах. */
    private static function readImapValue(string $raw, int &$position, int $depth): mixed
    {
        $length = strlen($raw);
        while ($position < $length && ctype_space($raw[$position])) { $position++; }
        if ($depth > 100 || $position >= $length) { throw new RuntimeException('history_structure_invalid'); }
        $first = $raw[$position++];
        if ($first === '(') {
            $values = [];
            while ($position < $length) {
                while ($position < $length && ctype_space($raw[$position])) { $position++; }
                if (($raw[$position] ?? '') === ')') { $position++; return $values; }
                $values[] = self::readImapValue($raw, $position, $depth + 1);
            }
        } elseif ($first === '"') {
            $value = '';
            while ($position < $length) {
                $char = $raw[$position++];
                if ($char === '"') { return $value; }
                if ($char === '\\' && $position < $length) { $char = $raw[$position++]; }
                $value .= $char;
            }
        } elseif ($first === '{') {
            $start = $position - 1;
            if (preg_match('/\G\{([0-9]+)\}\r\n/', $raw, $match, 0, $start)) {
                $size = (int)$match[1];
                $position = $start + strlen($match[0]);
                if ($size <= $length - $position) {
                    $value = substr($raw, $position, $size);
                    $position += $size;
                    return $value;
                }
            }
        } elseif ($first !== ')') {
            $start = $position - 1;
            while ($position < $length && !ctype_space($raw[$position]) && !in_array($raw[$position], ['(', ')'], true)) { $position++; }
            $value = substr($raw, $start, $position - $start);
            return strtoupper($value) === 'NIL' ? null : $value;
        }
        throw new RuntimeException('history_structure_invalid');
    }

    /** RFC 3501 BODYSTRUCTURE: только inline text/plain и text/html, без message/rfc822. */
    public static function textParts(array $node, string $prefix = ''): array
    {
        $multipart = isset($node[0]) && is_array($node[0]);
        $children = 0;
        if ($multipart) { while (isset($node[$children]) && is_array($node[$children])) { $children++; } }
        $disposition = $node[$multipart ? $children + 2 : 9] ?? null;
        if (is_array($disposition) && (strtolower((string)($disposition[0] ?? '')) === 'attachment' || self::hasFilename($disposition[1] ?? []))) { return []; }
        if ($multipart) {
            $parts = [];
            for ($i = 0; $i < $children; $i++) {
                array_push($parts, ...self::textParts($node[$i], ($prefix !== '' ? $prefix . '.' : '') . ($i + 1)));
            }
            return $parts;
        }
        if (strtolower((string)($node[0] ?? '')) !== 'text' || self::hasFilename($node[2] ?? [])) { return []; }
        $type = strtolower((string)($node[1] ?? ''));
        if (!in_array($type, ['plain', 'html'], true)) { return []; }
        $params = is_array($node[2] ?? null) ? $node[2] : [];
        $charset = 'UTF-8';
        for ($i = 0; $i + 1 < count($params); $i += 2) {
            if (strtolower((string)$params[$i]) === 'charset') { $charset = (string)$params[$i + 1]; }
        }
        return [['section' => $prefix ?: '1', 'type' => $type, 'charset' => $charset, 'encoding' => strtolower((string)($node[5] ?? '7bit'))]];
    }

    private static function hasFilename(mixed $params): bool
    {
        if (!is_array($params)) { return false; }
        for ($i = 0; $i < count($params); $i += 2) {
            if (preg_match('/^(?:name|filename)(?:\*.*)?$/i', (string)$params[$i])) { return true; }
        }
        return false;
    }

    public static function decodeText(string $raw, string $encoding, string $charset, bool $html): string
    {
        if ($encoding === 'base64') { $raw = base64_decode($raw, false); }
        if ($encoding === 'quoted-printable') { $raw = quoted_printable_decode($raw); }
        $raw = mb_convert_encoding($raw, 'UTF-8', $charset);
        if ($html) {
            $raw = preg_replace('~<(script|style|head)\b[^>]*>.*?</\1>~is', '', $raw);
            $raw = preg_replace('~<(?:br\b[^>]*|/p|/div|/tr)>~i', "\n", $raw);
            $raw = html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        $raw = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $raw);
        return trim(preg_replace('/\n{3,}/', "\n\n", str_replace("\r\n", "\n", $raw)));
    }

    public function close(): void
    {
        // Закрываем сокет без ещё одного сетевого ожидания LOGOUT за пределами бюджета.
        if ($this->client !== null) {
            $connection = $this->client->getConnection();
            if (property_exists($connection, 'stream')) {
                if (is_resource($connection->stream)) { fclose($connection->stream); }
                $connection->stream = false;
            }
            $this->client->disconnect();
        }
        $this->client = null;
    }
}

<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../classes/ServiceHistoryImapReader.php';

function verify(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException('FAIL: ' . $label); }
    echo "OK: $label\n";
}

final class HistoryFakeReader implements ServiceMailHistoryReader
{
    public int $calls = 0;
    public bool $closed = false;
    public bool $fail = false;
    public bool $folderFail = false;
    public function __construct(public array $messages = []) {}
    public function folders(): array
    {
        $this->calls++;
        if ($this->fail) { throw new RuntimeException('secret_password_do_not_print'); }
        return [['name' => 'INBOX', 'path' => 'INBOX'], ['name' => 'Sent', 'path' => 'Sent'],
            ['name' => 'Архив', 'path' => 'Archive'], ['name' => 'Спам', 'path' => 'Spam']];
    }
    public function candidates(array $folder, string $email): array
    {
        $this->calls++;
        if ($this->folderFail && $folder['path'] === 'Archive') { throw new RuntimeException('fail'); }
        if ($folder['path'] !== 'INBOX') { return []; }
        return $this->messages;
    }
    public function message(array $candidate): array { $this->calls++; return $candidate; }
    public function close(): void { $this->closed = true; }
}

function message(string $id, string $text, int $time = 1, array $extra = []): array
{
    return array_replace(['message_id' => $id, 'text' => $text, 'timestamp' => $time,
        'date' => gmdate('c', $time), 'subject' => 'Обучение', 'from' => ['client@example.org'],
        'to' => ['service@example.org'], 'cc' => [], 'references' => [], 'in_reply_to' => [],
        'truncated' => false], $extra);
}

$reader = new HistoryFakeReader();
$result = (new ServiceMailHistory($reader, false))->lookup('client@example.org');
verify($result['status'] === 'disabled' && $reader->calls === 0, 'выключено — нет внешних вызовов');
$result = (new ServiceMailHistory($reader, true))->lookup("bad\r\naddress");
verify($result['status'] === 'unavailable' && $reader->calls === 0, 'некорректный адрес отклонён');

$messages = [
    message('root', 'Инструкция ФГОС — Практикум', 10, ['from' => ['service@example.org'], 'to' => ['CLIENT@example.org']]),
    message('reply', 'Спасибо, а где документ?', 20, ['in_reply_to' => ['root']]),
    message('reply2', 'Отправим завтра', 30, ['references' => ['root', 'reply']]),
    message('other', 'Другая школа, курс и диплом', 40),
    message('wrong-address', 'https://fgos.pro/kursy/', 50, ['from' => ['notclient@example.org'], 'to' => ['other@example.org']]),
    message('same-subject', 'Без маркера', 60),
    message('spoof-domain', 'https://fgos.pro.attacker.org', 70),
    message('fake-brand', 'суперФГОС-Практикум123', 80),
    message('cc', 'fgos.pro', 90, ['from' => ['service@example.org'], 'to' => [], 'cc' => ['client@example.org']]),
];
$reader = new HistoryFakeReader(array_merge($messages, [$messages[0]]));
$result = (new ServiceMailHistory($reader, true))->lookup('Client@example.org');
$byId = array_column($result['messages'], null, 'message_id');
verify($result['status'] === 'ok' && count($byId) === 4 && $reader->closed, 'только свой адрес и проект; дубли удалены; соединение закрыто');
verify($byId['root']['direction'] === 'outbound' && $byId['reply']['direction'] === 'inbound', 'входящие, исходящие и регистр адреса');
verify($byId['reply2']['project_evidence']['type'] === 'thread', 'цепочка без повторения маркера');
verify(array_column($result['messages'], 'message_id') === ['cc', 'reply2', 'reply', 'root'], 'от новых к старым по общей дате');

foreach (['ФГОСПрактикум', 'фгос практикум', 'ФГОС-ПРАКТИКУМ', 'https://fgos.pro/path', 'info@fgos.pro'] as $marker) {
    verify(count(ServiceMailHistory::projectMessages([message('m', $marker)])) === 1, 'маркер: ' . $marker);
}
$siblings = [message('p', 'Другой проект'), message('a', 'fgos.pro', 2, ['references' => ['p']]),
    message('b', 'Другой проект', 3, ['references' => ['p']])];
verify(count(ServiceMailHistory::projectMessages($siblings)) === 1, 'общий предок не связывает разные проекты');
foreach ([['name' => 'INBOX/Черновики'], ['name' => 'Trash'], ['name' => 'INBOX.Deleted Items'],
    ['name' => 'Ненужное', 'flags' => ['\\Junk']], ['name' => 'Container', 'flags' => ['\\Noselect']]] as $folder) {
    verify(ServiceMailHistory::excludedFolder($folder), 'исключена служебная папка ' . $folder['name']);
}
verify(!ServiceMailHistory::excludedFolder(['name' => 'Archive/Курсы']), 'архив включён');

$reader = new HistoryFakeReader();
$result = (new ServiceMailHistory($reader, true))->lookup('client@example.org');
verify($result['status'] === 'ok' && $result['no_matches'] === true, 'полный поиск без совпадений');
$mismatch = new HistoryFakeReader([message('skip', 'fgos.pro', 1, ['address_matches' => false])]);
$skipped = (new ServiceMailHistory($mismatch, true))->lookup('client@example.org');
verify($skipped['candidates_read'] === 0 && $skipped['messages'] === [], 'тело ложного совпадения адреса не читается');
$reader->fail = true;
$result = (new ServiceMailHistory($reader, true))->lookup('client@example.org');
verify($result['status'] === 'unavailable' && $result['no_matches'] === null && !str_contains(json_encode($result), 'secret_password'), 'ошибка авторизации не выдаётся за отсутствие писем и не раскрывает секрет');
$reader = new HistoryFakeReader([message('a', 'fgos.pro')]);
$reader->folderFail = true;
$result = (new ServiceMailHistory($reader, true))->lookup('client@example.org');
verify($result['status'] === 'partial' && count($result['messages']) === 1, 'ошибка одной папки сохраняет найденную историю');
$result = (new ServiceMailHistory(new HistoryFakeReader([message('short', 'fgos.pro', 1, ['truncated' => true])]), true))->lookup('client@example.org');
verify($result['status'] === 'partial' && in_array('body_truncated', $result['reasons'], true), 'усечённый текст явно помечает неполноту');
$tick = 0;
$result = (new ServiceMailHistory(new HistoryFakeReader(), true, static function () use (&$tick): float { return $tick++ === 0 ? 0 : 61; }))->lookup('client@example.org');
verify($result['status'] === 'unavailable' && in_array('timeout', $result['reasons'], true), 'общий дедлайн');
$reader = new HistoryFakeReader(array_map(static fn(int $i): array => message((string)$i, 'fgos.pro', $i), range(1, 201)));
$result = (new ServiceMailHistory($reader, true))->lookup('client@example.org');
verify($result['status'] === 'partial' && $result['candidates_read'] === 200 && count($result['messages']) === 50
    && in_array('candidate_limit', $result['reasons'], true) && in_array('message_limit', $result['reasons'], true), '200 кандидатов / 50 писем; явная неполнота');
verify($result['messages'][0]['timestamp'] === 201 && $result['no_matches'] === null, 'лимиты сохраняют новые письма');

$plain = ['TEXT', 'PLAIN', ['CHARSET', 'WINDOWS-1251'], null, null, 'BASE64', 100, 1, null, null];
$html = ['TEXT', 'HTML', ['CHARSET', 'UTF-8'], null, null, 'QUOTED-PRINTABLE', 200, 1, null, null];
$attachment = ['TEXT', 'PLAIN', ['NAME', 'secret.txt'], null, null, 'BASE64', 900, 1, null, ['ATTACHMENT', ['FILENAME', 'secret.txt']]];
$structure = [[$plain, $html, 'ALTERNATIVE', null, null], $attachment, ['APPLICATION', 'PDF', null, null, null, 'BASE64', 1000], 'MIXED', null, null];
$parts = ServiceHistoryImapReader::textParts($structure);
verify(array_column($parts, 'section') === ['1.1', '1.2'], 'MIME: выбираются только текстовые inline-части, файлы не скачиваются');
$mailru = ['* 1 FETCH (UID 1 BODYSTRUCTURE (("text" "plain" ("charset" "utf-8") NIL NIL "base64" 992 0 NIL NIL NIL NIL)("text" "html" ("charset" "utf-8") NIL NIL "base64" 15960 0 NIL NIL NIL NIL) "alternative" ("boundary" NIL)))' . "\r\n"];
$parsed = ServiceHistoryImapReader::parseStructureResponse($mailru);
verify($parsed[0][2][1] === 'utf-8' && $parsed[1][5] === 'base64' && count(ServiceHistoryImapReader::textParts($parsed)) === 2, 'регрессия Mail.ru: соседние списки и кавычка перед закрывающей скобкой');
$literal = ServiceHistoryImapReader::parseStructureResponse(["* 1 FETCH (BODYSTRUCTURE (\"text\" \"plain\" (\"charset\" {5}\r\nUTF-8) NIL NIL \"7bit\" 10 1))\r\n"]);
verify($literal[2][1] === 'UTF-8', 'BODYSTRUCTURE с IMAP literal');
try {
    ServiceHistoryImapReader::parseStructureResponse(['* 1 FETCH (BODYSTRUCTURE ("text"']);
    verify(false, 'незавершённая структура отклоняется');
} catch (RuntimeException $expected) { verify(true, 'незавершённая структура отклоняется'); }
verify(ServiceHistoryImapReader::decodeText(base64_encode(mb_convert_encoding('Ваш доступ', 'WINDOWS-1251', 'UTF-8')), 'base64', 'WINDOWS-1251', false) === 'Ваш доступ', 'декодирование base64 / windows-1251');
$text = ServiceHistoryImapReader::decodeText('<head>секрет</head><style>x</style><p>ФГОС&nbsp;Практикум<br>Доступ</p><script>evil()</script>', '7bit', 'UTF-8', true);
verify(str_contains($text, 'Доступ') && !str_contains($text, 'evil') && !str_contains($text, 'секрет'), 'HTML очищается без исполнения');

// Фиктивный протокол падает при любой неожиданной команде: никаких SELECT/STORE/полных тел.
final class HistoryProtocolSpy
{
    public array $calls = [];
    public function setConnectionTimeout(int $seconds): void {}
    public function escapeString(string $value): string { return '"' . $value . '"'; }
    public function search(array $query): object
    {
        verify(str_starts_with($query[0], 'UNDELETED OR FROM '), 'IMAP-поиск исключает удалённые письма');
        return $this->response([1]);
    }
    public function examineFolder(string $path): object { $this->calls[] = 'EXAMINE'; return $this->response([]); }
    public function requestAndResponse(string $command, array $params, bool $raw): object
    {
        verify($command === 'UID FETCH' && $params === ['1', '(BODYSTRUCTURE)'] && $raw, 'BODYSTRUCTURE читается без ошибочного токенизатора Webklex');
        $this->calls[] = ['BODYSTRUCTURE'];
        return $this->response([]);
    }
    public function fetch(array $items, array $uids): object
    {
        $this->calls[] = $items;
        if ($items === ['INTERNALDATE', 'BODY.PEEK[HEADER]<0.65536>']) {
            return $this->response([1 => ['INTERNALDATE' => '1-Jan-2026 00:00:00 +0000',
                'BODY[HEADER]<0>' => "From: client@example.org\r\nTo: service@example.org\r\nDate: Thu, 1 Jan 2026 00:00:00 +0000\r\n"]]);
        }
        if ($items === ['BODYSTRUCTURE']) {
            return $this->response([1 => ['TEXT', 'PLAIN', ['CHARSET', 'UTF-8'], null, null, '7BIT', 30, 1]]);
        }
        if ($items === ['BODY.PEEK[1]<0.131072>', 'UID']) { return $this->response([1 => ['BODY[1]<0>' => 'Письмо ФГОС-Практикум', 'UID' => 1]]); }
        throw new RuntimeException('Недопустимая команда');
    }
    private function response(array $data): object { return new class($data) {
        public function __construct(private array $data) {}
        public function validatedData(): array { return $this->data; }
        public function validate(): void {}
        public function getResponse(): array { return ['* 1 FETCH (BODYSTRUCTURE ("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "7BIT" 30 1))' . "\r\n"]; }
    }; }
}
final class HistoryClientSpy
{
    public bool $closed = false;
    public function __construct(public HistoryProtocolSpy $protocol) {}
    public function getConnection(): object { return $this->protocol; }
    public function getConfig(): object { return (new Webklex\PHPIMAP\ClientManager())->getConfig(); }
    public function disconnect(): void { $this->closed = true; }
}
$spy = new HistoryClientSpy(new HistoryProtocolSpy());
$reader = new ServiceHistoryImapReader(['username' => 'service@example.org', 'password' => 'fake']);
$property = new ReflectionProperty($reader, 'client');
$property->setValue($reader, $spy);
$candidates = $reader->candidates(['path' => 'INBOX', 'name' => 'INBOX'], 'client@example.org');
verify(count($candidates) === 1 && $candidates[0]['address_matches'] === true && $candidates[0]['timestamp'] > 0, 'транспорт получает дату и заголовки без тела');
$candidates = $reader->candidates(['path' => 'INBOX', 'name' => 'INBOX'], 'other@example.org');
verify($candidates[0]['address_matches'] === false, 'подстрочный IMAP-результат перепроверяется по адресам заголовка');
$message = $reader->message(['folder' => 'INBOX', 'folder_name' => 'INBOX', 'uid' => 1,
    'timestamp' => 1, 'header_truncated' => false,
    'header' => "From: Client <client@example.org>\r\nTo: service@example.org\r\nSubject: =?UTF-8?B?0JTQvtGB0YLRg9C/?=\r\nMessage-ID: <one@example.org>\r\nIn-Reply-To: <parent@example.org>\r\nReferences: <root@example.org>\r\n <parent@example.org>\r\nDate: Thu, 1 Jan 2026 00:00:00 +0000\r\n"]);
$reader->close();
verify($message['subject'] === 'Доступ' && $message['from'] === ['client@example.org'] && $message['message_id'] === 'one@example.org', 'реальный парсер заголовков Webklex');
verify($message['references'] === ['root@example.org', 'parent@example.org'] && $message['in_reply_to'] === ['parent@example.org'], 'разбор folded References и In-Reply-To');
verify($spy->protocol->calls[0] === 'EXAMINE' && $spy->closed, 'транспорт использует только EXAMINE, BODYSTRUCTURE, BODY.PEEK и закрытие');
echo "Все проверки пройдены.\n";

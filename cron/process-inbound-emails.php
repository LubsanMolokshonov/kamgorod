#!/usr/bin/env php
<?php
/**
 * Cron Script: Inbound Emails → Support Alerts
 *
 * Читает непрочитанные письма с IMAP-ящика info@fgos.pro, классифицирует через
 * заголовки + YandexGPT и:
 *   - ответы на наши исходящие → дописывает в alert_messages существующего алерта;
 *   - явные алерты → создаёт запись в support_alerts с source='email';
 *   - всё остальное → помечает \Seen и оставляет в INBOX (лог в inbound_email_log).
 *
 * Расписание: каждые 5 минут.
 * Crontab (Docker prod):
 *   0,5,10,15,20,25,30,35,40,45,50,55 * * * * docker exec pedagogy_web php /var/www/html/cron/process-inbound-emails.php >> /var/log/cron-inbound.log 2>&1
 *
 * Флаги окружения:
 *   DRY_RUN=1 — не пишет в support_alerts/alert_messages, не ставит \Seen, только лог в inbound_email_log.
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

set_time_limit(0);

if (!function_exists('imap_open')) {
    fwrite(STDERR, "PHP IMAP extension is not enabled\n");
    exit(1);
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

TelegramNotifier::registerFatalHandler('process-inbound-emails');

$dryRun = (getenv('DRY_RUN') === '1');
$batchLimit = 50;
$lockFile = '/tmp/inbound_emails.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    if ($lockAge > 600) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Stale lock removed (age={$lockAge}s)\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

$mbx = null;
try {
    if (IMAP_USERNAME === '' || IMAP_PASSWORD === '') {
        throw new RuntimeException('IMAP credentials not configured (IMAP_USERNAME / IMAP_PASSWORD)');
    }

    $mailboxRef = sprintf('{%s:%d/imap/%s}%s', IMAP_HOST, IMAP_PORT, IMAP_ENCRYPTION, IMAP_MAILBOX);
    echo date('Y-m-d H:i:s') . " - Opening {$mailboxRef} ...\n";

    // 0 = read-write (нужно, чтобы ставить флаг \Seen)
    $mbx = @imap_open($mailboxRef, IMAP_USERNAME, IMAP_PASSWORD, 0);
    if (!$mbx) {
        throw new RuntimeException('imap_open failed: ' . imap_last_error());
    }

    $uids = imap_search($mbx, 'UNSEEN', SE_UID) ?: [];
    if (empty($uids)) {
        echo date('Y-m-d H:i:s') . " - No unseen messages.\n";
        exit(0);
    }
    if (count($uids) > $batchLimit) {
        $uids = array_slice($uids, 0, $batchLimit);
    }
    echo date('Y-m-d H:i:s') . " - Processing " . count($uids) . " unseen message(s)" . ($dryRun ? ' [DRY_RUN]' : '') . "\n";

    $alertService = new AlertService($db);
    $processor = new InboundEmailProcessor($db, $alertService, 0.6, $dryRun);

    $stats = ['alert_new' => 0, 'alert_reply' => 0, 'not_alert' => 0, 'skipped' => 0, 'error' => 0];

    foreach ($uids as $uid) {
        $uid = (int)$uid;
        try {
            $email = fetch_message($mbx, $uid);
            if ($email === null) {
                $stats['error']++;
                continue;
            }

            $result = $processor->process($email);
            $stats[$result['classification']] = ($stats[$result['classification']] ?? 0) + 1;

            $shouldMarkSeen = !$dryRun && in_array($result['classification'], ['alert_new', 'alert_reply', 'not_alert', 'skipped'], true);
            if ($shouldMarkSeen) {
                @imap_setflag_full($mbx, (string)$uid, '\\Seen', ST_UID);
            }

            echo date('Y-m-d H:i:s') . sprintf(
                " - uid=%d from=%s class=%s reason=%s alert=%s\n",
                $uid,
                $email['from_email'],
                $result['classification'],
                $result['reason'] ?? '-',
                $result['alert_id'] !== null ? '#' . $result['alert_id'] : '-'
            );
        } catch (Throwable $e) {
            $stats['error']++;
            error_log('process-inbound-emails uid=' . $uid . ': ' . $e->getMessage());
            echo date('Y-m-d H:i:s') . " - uid={$uid} EXCEPTION: " . $e->getMessage() . "\n";
        }
    }

    echo date('Y-m-d H:i:s') . " - Done. " . json_encode($stats) . "\n";

} catch (Throwable $e) {
    error_log('process-inbound-emails fatal: ' . $e->getMessage());
    echo date('Y-m-d H:i:s') . " - FATAL: " . $e->getMessage() . "\n";
    try {
        TelegramNotifier::instance($db)->alert(
            'cron_exception_process-inbound-emails',
            '[Cron] Exception: process-inbound-emails',
            ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()],
            'critical'
        );
    } catch (Throwable $ignored) {
    }
} finally {
    if ($mbx) {
        @imap_close($mbx);
    }
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ---------------- helpers ----------------

/**
 * Извлечь Message-ID из заголовков (имя отправителя, тело, attachments) одного письма по UID.
 *
 * @return array|null нормализованный массив для InboundEmailProcessor::process(), либо null при ошибке
 */
function fetch_message($mbx, int $uid): ?array
{
    $headersRaw = imap_fetchheader($mbx, $uid, FT_UID);
    if (!is_string($headersRaw) || $headersRaw === '') return null;
    $headerObj = imap_rfc822_parse_headers($headersRaw);

    $structure = imap_fetchstructure($mbx, $uid, FT_UID);
    if (!$structure) return null;

    $bodyText = '';
    $bodyHtml = '';
    $attachments = [];
    collect_parts($mbx, $uid, $structure, '', $bodyText, $bodyHtml, $attachments);

    if ($bodyText === '' && $bodyHtml !== '') {
        $bodyText = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml) ?? $bodyHtml));
    }

    $fromEmail = '';
    $fromName = null;
    if (isset($headerObj->from[0])) {
        $f = $headerObj->from[0];
        $fromEmail = strtolower(trim(($f->mailbox ?? '') . '@' . ($f->host ?? '')));
        $fromName = isset($f->personal) ? decode_mime_str($f->personal) : null;
    }

    $subject = isset($headerObj->subject) ? decode_mime_str($headerObj->subject) : null;
    $messageId = isset($headerObj->message_id) ? trim((string)$headerObj->message_id) : '';
    $inReplyTo = isset($headerObj->in_reply_to) ? trim((string)$headerObj->in_reply_to) : null;
    $references = null;
    if (preg_match('/^References:\s*(.+)$/im', $headersRaw, $m)) {
        $references = trim($m[1]);
    }
    $receivedAt = isset($headerObj->date) ? date('Y-m-d H:i:s', strtotime((string)$headerObj->date) ?: time()) : date('Y-m-d H:i:s');

    // Дополнительно собираем все заголовки в map (для эвристик: Auto-Submitted, Precedence, List-Unsubscribe и т.д.)
    $headersMap = parse_headers_map($headersRaw);

    return [
        'uid' => $uid,
        'message_id' => $messageId,
        'in_reply_to' => $inReplyTo,
        'references' => $references,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'subject' => $subject,
        'body_text' => $bodyText,
        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
        'attachments' => $attachments,
        'received_at' => $receivedAt,
        'headers' => $headersMap,
        'raw_size' => strlen($headersRaw) + strlen($bodyText) + strlen($bodyHtml),
    ];
}

function collect_parts($mbx, int $uid, $structure, string $partNum, string &$bodyText, string &$bodyHtml, array &$attachments): void
{
    $isMulti = isset($structure->parts) && is_array($structure->parts);
    if ($isMulti) {
        foreach ($structure->parts as $i => $sub) {
            $sub_partNum = $partNum === '' ? (string)($i + 1) : $partNum . '.' . ($i + 1);
            collect_parts($mbx, $uid, $sub, $sub_partNum, $bodyText, $bodyHtml, $attachments);
        }
        return;
    }

    $section = $partNum === '' ? '1' : $partNum;
    $data = imap_fetchbody($mbx, $uid, $section, FT_UID);
    if ($data === false || $data === '') return;

    // декодирование передачи
    switch ((int)($structure->encoding ?? 0)) {
        case 3: $data = base64_decode($data) ?: ''; break;
        case 4: $data = quoted_printable_decode($data); break;
    }

    $params = [];
    foreach (($structure->parameters ?? []) as $p) $params[strtolower($p->attribute)] = $p->value;
    foreach (($structure->dparameters ?? []) as $p) $params[strtolower($p->attribute)] = $p->value;
    $charset = $params['charset'] ?? 'UTF-8';
    if (strtoupper($charset) !== 'UTF-8') {
        $converted = @iconv($charset, 'UTF-8//IGNORE', $data);
        if ($converted !== false) $data = $converted;
    }

    $disposition = strtoupper((string)($structure->disposition ?? ''));
    $filename = $params['filename'] ?? $params['name'] ?? null;

    if ($disposition === 'ATTACHMENT' || $filename) {
        $attachments[] = [
            'name' => $filename ?: ('attachment-' . $section),
            'size' => strlen($data),
        ];
        return;
    }

    $type = (int)($structure->type ?? 0);
    $subtype = strtoupper((string)($structure->subtype ?? ''));
    if ($type === 0 /* TYPETEXT */) {
        if ($subtype === 'PLAIN' && $bodyText === '') {
            $bodyText = $data;
        } elseif ($subtype === 'HTML' && $bodyHtml === '') {
            $bodyHtml = $data;
        }
    }
}

function decode_mime_str(?string $s): ?string
{
    if ($s === null || $s === '') return $s;
    $elements = imap_mime_header_decode($s);
    $out = '';
    foreach ($elements as $el) {
        $charset = $el->charset === 'default' ? 'UTF-8' : $el->charset;
        $text = $el->text;
        if (strtoupper($charset) !== 'UTF-8') {
            $converted = @iconv($charset, 'UTF-8//IGNORE', $text);
            if ($converted !== false) $text = $converted;
        }
        $out .= $text;
    }
    return $out;
}

function parse_headers_map(string $raw): array
{
    $map = [];
    $current = null;
    foreach (preg_split('/\r?\n/', $raw) as $line) {
        if ($line === '') continue;
        if (preg_match('/^([A-Za-z0-9\-]+):\s*(.*)$/', $line, $m)) {
            $current = strtolower($m[1]);
            $map[$current] = isset($map[$current]) ? $map[$current] . ',' . $m[2] : $m[2];
        } elseif ($current !== null && (str_starts_with($line, ' ') || str_starts_with($line, "\t"))) {
            $map[$current] .= ' ' . trim($line);
        }
    }
    return $map;
}

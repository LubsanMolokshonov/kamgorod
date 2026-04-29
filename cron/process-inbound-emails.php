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
 * IMAP-доступ через webklex/php-imap (pure PHP), чтобы не тащить native imap-extension
 * (libc-client deprecated в Debian Bookworm).
 *
 * Расписание: каждые 5 минут (см. Dockerfile, /etc/cron.d/email-automation).
 *
 * Флаги окружения:
 *   DRY_RUN=1 — не пишет в support_alerts/alert_messages, не ставит \Seen, только лог в inbound_email_log.
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}

// Хард-лимит по времени: cron запускается каждые 5 минут, поэтому скрипт обязан
// уложиться, иначе предыдущий процесс будет держать lock и блокировать следующие.
// 28.04.2026 был случай: webklex IMAP завис на 22 часа, lock не снимался — все
// последующие запуски выходили из-за «Another instance is running».
set_time_limit(240);
ini_set('default_socket_timeout', '30'); // и для IMAP-сокета вебклекса

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// bootstrap.php читает ключи через getenv() — прокидываем PHP-константы в env
putenv('YANDEX_GPT_API_KEY=' . YANDEX_GPT_API_KEY);
putenv('YANDEX_GPT_FOLDER_ID=' . YANDEX_GPT_FOLDER_ID);
putenv('YANDEX_GPT_MODEL=' . (defined('YANDEX_GPT_MODEL') ? YANDEX_GPT_MODEL : 'yandexgpt-lite'));
putenv('DB_HOST=' . DB_HOST);
putenv('DB_NAME=' . DB_NAME);
putenv('DB_USER=' . DB_USER);
putenv('DB_PASS=' . DB_PASS);
putenv('SITE_URL=' . SITE_URL);

require_once BASE_PATH . '/vendor/autoload.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

use Webklex\PHPIMAP\ClientManager;
use Webklex\PHPIMAP\Message;

TelegramNotifier::registerFatalHandler('process-inbound-emails');

$dryRun = (getenv('DRY_RUN') === '1');
$batchLimit = 50;
$lockFile = '/tmp/inbound_emails.lock';

if (file_exists($lockFile)) {
    $lockAge = time() - filemtime($lockFile);
    $lockPid = (int)trim((string)@file_get_contents($lockFile));
    // Считаем lock протухшим, если: (а) старше 5 минут (= cron-интервалу); либо
    // (б) процесса с таким PID уже нет (предыдущий упал, не успев убрать lock).
    $pidAlive = $lockPid > 0 && posix_kill($lockPid, 0);
    if ($lockAge > 300 || !$pidAlive) {
        unlink($lockFile);
        echo date('Y-m-d H:i:s') . " - Stale lock removed (age={$lockAge}s, pid={$lockPid}, alive=" . ($pidAlive ? 'yes' : 'no') . ")\n";
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running (pid={$lockPid}, age={$lockAge}s). Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

// Гарантированное снятие lock-а на любом выходе из скрипта (включая fatal).
register_shutdown_function(static function () use ($lockFile) {
    if (file_exists($lockFile) && (int)trim((string)@file_get_contents($lockFile)) === getmypid()) {
        @unlink($lockFile);
    }
});

$client = null;
try {
    if (IMAP_USERNAME === '' || IMAP_PASSWORD === '') {
        throw new RuntimeException('IMAP credentials not configured (IMAP_USERNAME / IMAP_PASSWORD)');
    }

    $cm = new ClientManager();
    $client = $cm->make([
        'host'           => IMAP_HOST,
        'port'           => IMAP_PORT,
        'encryption'     => IMAP_ENCRYPTION === 'ssl' ? 'ssl' : (IMAP_ENCRYPTION ?: 'ssl'),
        'validate_cert'  => true,
        'username'       => IMAP_USERNAME,
        'password'       => IMAP_PASSWORD,
        'protocol'       => 'imap',
        'authentication' => null,
        'timeout'        => 30,
    ]);
    // На случай если клиент уже был построен с дефолтом — пробросим явно.
    if (method_exists($client, 'getConnection') && $client->getConnection()) {
        $conn = $client->getConnection();
        if (method_exists($conn, 'setConnectionTimeout')) {
            $conn->setConnectionTimeout(30);
        }
    }
    echo date('Y-m-d H:i:s') . sprintf(" - Connecting to %s:%d as %s ...\n", IMAP_HOST, IMAP_PORT, IMAP_USERNAME);
    $client->connect();

    $folder = $client->getFolderByPath(IMAP_MAILBOX);
    if (!$folder) {
        throw new RuntimeException('IMAP folder not found: ' . IMAP_MAILBOX);
    }

    /** @var \Webklex\PHPIMAP\Support\MessageCollection $messages */
    $messages = $folder->query()
        ->unseen()
        ->setFetchOrder('asc')
        ->limit($batchLimit)
        ->get();

    if ($messages->isEmpty()) {
        echo date('Y-m-d H:i:s') . " - No unseen messages.\n";
        exit(0);
    }
    echo date('Y-m-d H:i:s') . " - Processing " . $messages->count() . " unseen message(s)" . ($dryRun ? ' [DRY_RUN]' : '') . "\n";

    $alertService = new AlertService($db);
    $processor = new InboundEmailProcessor($db, $alertService, 0.6, $dryRun);

    $stats = ['alert_new' => 0, 'alert_reply' => 0, 'not_alert' => 0, 'skipped' => 0, 'error' => 0];

    foreach ($messages as $message) {
        $uid = (int)$message->getUid();
        try {
            $email = normalize_message($message);
            if ($email === null) {
                $stats['error']++;
                continue;
            }

            $result = $processor->process($email);
            $stats[$result['classification']] = ($stats[$result['classification']] ?? 0) + 1;

            $shouldMarkSeen = !$dryRun && in_array($result['classification'], ['alert_new', 'alert_reply', 'not_alert', 'skipped'], true);
            if ($shouldMarkSeen) {
                try { $message->setFlag('Seen'); } catch (Throwable $ignored) {}
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
    if ($client) {
        try { $client->disconnect(); } catch (Throwable $ignored) {}
    }
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

// ---------------- helpers ----------------

/**
 * Преобразует Webklex\PHPIMAP\Message в нормализованный массив
 * для InboundEmailProcessor::process().
 */
function normalize_message(Message $msg): ?array
{
    $uid = (int)$msg->getUid();

    $fromList = $msg->getFrom();
    $fromEmail = '';
    $fromName = null;
    if (!empty($fromList) && isset($fromList[0])) {
        $f = $fromList[0];
        $fromEmail = strtolower(trim((string)($f->mail ?? '')));
        $fromName = !empty($f->personal) ? (string)$f->personal : null;
    }

    $subject = decode_mime_header(trim((string)$msg->getSubject()));
    if ($fromName !== null) {
        $fromName = decode_mime_header($fromName);
    }

    $messageId = trim((string)$msg->getMessageId());
    if ($messageId !== '' && $messageId[0] !== '<') {
        $messageId = '<' . $messageId . '>';
    }

    $inReplyTo = trim((string)$msg->getInReplyTo());
    if ($inReplyTo === '') $inReplyTo = null;
    elseif ($inReplyTo[0] !== '<') $inReplyTo = '<' . $inReplyTo . '>';

    $referencesAttr = $msg->getReferences();
    $references = null;
    if ($referencesAttr) {
        $referencesArr = is_array($referencesAttr->get()) ? $referencesAttr->get() : [(string)$referencesAttr];
        $referencesArr = array_filter(array_map('strval', $referencesArr));
        if ($referencesArr) {
            $references = implode(' ', array_map(static fn($r) => $r[0] === '<' ? $r : '<' . $r . '>', $referencesArr));
        }
    }

    try {
        $dt = $msg->getDate();
        $receivedAt = $dt ? $dt->format('Y-m-d H:i:s') : date('Y-m-d H:i:s');
    } catch (Throwable $e) {
        $receivedAt = date('Y-m-d H:i:s');
    }

    $bodyText = (string)$msg->getTextBody();
    $bodyHtml = (string)$msg->getHTMLBody();
    if ($bodyText === '' && $bodyHtml !== '') {
        $bodyText = trim(strip_tags(preg_replace('/<br\s*\/?>/i', "\n", $bodyHtml) ?? $bodyHtml));
    }

    $attachments = [];
    foreach ($msg->getAttachments() as $att) {
        $attachments[] = [
            'name' => (string)($att->getName() ?: 'attachment'),
            'size' => (int)$att->getSize(),
        ];
    }

    $headersMap = [];
    $headerObj = $msg->getHeader();
    if ($headerObj) {
        foreach ($headerObj->getAttributes() as $name => $attr) {
            $val = $attr->toString();
            $headersMap[strtolower((string)$name)] = $val;
        }
    }

    return [
        'uid' => $uid,
        'message_id' => $messageId,
        'in_reply_to' => $inReplyTo,
        'references' => $references,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'subject' => $subject !== '' ? $subject : null,
        'body_text' => $bodyText,
        'body_html' => $bodyHtml !== '' ? $bodyHtml : null,
        'attachments' => $attachments,
        'received_at' => $receivedAt,
        'headers' => $headersMap,
        'raw_size' => strlen($bodyText) + strlen($bodyHtml),
    ];
}

/**
 * Декодирует MIME-encoded-word (`=?utf-8?Q?...?=`) → plain UTF-8.
 * Webklex возвращает Subject/From-name «как есть», поэтому декодируем сами.
 */
function decode_mime_header(string $value): string
{
    if ($value === '') return '';
    if (function_exists('mb_decode_mimeheader')) {
        $decoded = @mb_decode_mimeheader($value);
        if ($decoded !== false && $decoded !== '') return $decoded;
    }
    if (function_exists('iconv_mime_decode')) {
        $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($decoded !== false && $decoded !== '') return $decoded;
    }
    return $value;
}

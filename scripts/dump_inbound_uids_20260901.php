#!/usr/bin/env php
<?php
/**
 * Ad-hoc: выгрузить тела писем из INBOX по списку IMAP UID.
 *
 * Повод: 01.09.2026 после восстановления доступа к YandexGPT бэклог из 25 писем
 * разобрался автоматически, но часть писем ушла в not_alert — надо глазами
 * проверить, не потеряно ли реальное обращение.
 *
 * Только чтение: флаги не трогает, в БД не пишет.
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/dump_inbound_uids_20260901.php 3930,3934,3940,3941,3951
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/vendor/autoload.php';

use Webklex\PHPIMAP\ClientManager;

$uids = array_filter(array_map('intval', explode(',', $argv[1] ?? '')));
if (!$uids) {
    die("Usage: dump_inbound_uids_20260901.php <uid,uid,...>\n");
}

$cm = new ClientManager();
$client = $cm->make([
    'host' => IMAP_HOST,
    'port' => IMAP_PORT,
    'encryption' => IMAP_ENCRYPTION ?: 'ssl',
    'validate_cert' => true,
    'username' => IMAP_USERNAME,
    'password' => IMAP_PASSWORD,
    'protocol' => 'imap',
    'authentication' => null,
    'timeout' => 30,
]);
$client->connect();
$folder = $client->getFolderByPath(IMAP_MAILBOX);

foreach ($uids as $uid) {
    $msg = $folder->query()->getMessageByUid($uid);
    if (!$msg) {
        echo "=== UID $uid — не найдено ===\n\n";
        continue;
    }
    $from = $msg->getFrom()[0] ?? null;
    echo "=== UID $uid ===\n";
    echo "From: " . ($from ? $from->mail : '?') . " (" . ($from->personal ?? '') . ")\n";
    echo "Date: " . (string)$msg->getDate() . "\n";
    echo "Subject: " . (string)$msg->getSubject() . "\n";
    $body = trim((string)$msg->getTextBody());
    if ($body === '') {
        $body = trim(strip_tags((string)$msg->getHTMLBody()));
    }
    echo "Body:\n" . mb_substr(preg_replace('/\n{3,}/', "\n\n", $body), 0, 1500) . "\n\n";
}
$client->disconnect();

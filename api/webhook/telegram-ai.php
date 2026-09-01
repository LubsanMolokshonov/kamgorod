<?php
declare(strict_types=1);

error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"ok":false}'; exit; }
if (TELEGRAM_WEBHOOK_SECRET === '') { http_response_code(503); echo '{"ok":false}'; exit; }
$got=(string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN']??'');
if (!hash_equals((string)TELEGRAM_WEBHOOK_SECRET,$got)) { http_response_code(403); echo '{"ok":false}'; exit; }
$raw=file_get_contents('php://input');
if ($raw !== false && strlen($raw) > 1048576) { http_response_code(413); echo '{"ok":false}'; exit; }
$payload=json_decode((string)$raw,true);
if (!is_array($payload)) { http_response_code(400); echo '{"ok":false}'; exit; }
try {
    $event=MessengerWebhookParser::telegram($payload);
    if ($event && $event['chat_id']!=='') (new MessengerQueue($db))->ingest($event);
    echo '{"ok":true}';
} catch(Throwable $e) { error_log('[telegram-ai] '.$e->getMessage()); http_response_code(500); echo '{"ok":false}'; }

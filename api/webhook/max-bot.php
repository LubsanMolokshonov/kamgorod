<?php
declare(strict_types=1);

error_reporting(E_ALL); ini_set('display_errors','0'); ini_set('log_errors','1');
define('BASE_PATH', dirname(__DIR__, 2));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo '{"success":false}'; exit; }
if (MAX_BOT_WEBHOOK_SECRET === '') { http_response_code(503); echo '{"success":false}'; exit; }
$got=(string)($_SERVER['HTTP_X_MAX_BOT_API_SECRET']??'');
if (!hash_equals((string)MAX_BOT_WEBHOOK_SECRET,$got)) { http_response_code(403); echo '{"success":false}'; exit; }
$raw=file_get_contents('php://input');
if ($raw !== false && strlen($raw) > 1048576) { http_response_code(413); echo '{"success":false}'; exit; }
$payload=json_decode((string)$raw,true);
if (!is_array($payload)) { http_response_code(400); echo '{"success":false}'; exit; }
try {
    $event=MessengerWebhookParser::max($payload);
    if ($event && $event['chat_id']!=='') (new MessengerQueue($db))->ingest($event);
    echo '{"success":true}';
} catch(Throwable $e) { error_log('[max-bot] '.$e->getMessage()); http_response_code(500); echo '{"success":false}'; }

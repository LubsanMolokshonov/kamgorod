<?php
/**
 * ChatPush Callback Webhook — приём событий из мессенджера «Макс».
 *
 * Принимает два вида POST-запросов (формат по docs2.chatpush.ru):
 *  1) Событие webhook `max_incoming_msg` / `max_bot_incoming_msg` — входящие И исходящие
 *     сообщения авторизованного MAX-аккаунта. Отвечаем ИИ только на direction=incoming
 *     (исходящие — это эхо наших же отправок, их пропускаем, чтобы не зациклиться).
 *     Структура: { "type":"max_incoming_msg", "payload":{ "new_message":{
 *         "message":{"id","timestamp","type","text","file_data":{download_url,caption}},
 *         "direction":"incoming|outgoing", "chat_phone","sender_phone_number","sender_name" },
 *       "delivery_id", "customer_id" } }
 *     Регистрируется одноразово: POST /api/v1/webhooks?url=<наш_url>&types[]=max_incoming_msg
 *     (см. scripts/chatpush-register-webhook.php).
 *  2) Колбэк статуса доставки (per-send callback_url) — объект delivery с status.status_id/
 *     description. Обновляет статус исходящего в ленте по delivery.id.
 *     Структура: { "delivery":{ "id", "status":{"status_id","description"}, ... }, "meta":{} }
 *
 * БЕЗОПАСНОСТЬ: эндпоинт публичный и тратит деньги (вызовы YandexGPT) + создаёт алерты,
 * поэтому fail-closed — без заданного CHATPUSH_CALLBACK_SECRET запросы отклоняются. У ChatPush
 * нет отдельного поля секрета для webhook, поэтому секрет зашивается в сам регистрируемый URL
 * (?secret=...) и читается здесь из $_GET['secret'] (также поддержан header X-Chatpush-Secret).
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// bootstrap.php читает ключи через getenv(), но у нас они в PHP-константах из .env
putenv('YANDEX_GPT_API_KEY=' . YANDEX_GPT_API_KEY);
putenv('YANDEX_GPT_FOLDER_ID=' . YANDEX_GPT_FOLDER_ID);
putenv('YANDEX_GPT_MODEL=' . (defined('YANDEX_GPT_MODEL') ? YANDEX_GPT_MODEL : 'yandexgpt-lite'));
putenv('DB_HOST=' . DB_HOST);
putenv('DB_NAME=' . DB_NAME);
putenv('DB_USER=' . DB_USER);
putenv('DB_PASS=' . DB_PASS);
putenv('SITE_URL=' . SITE_URL);

require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';
require_once BASE_PATH . '/classes/ChatpushClient.php';

// Всегда отвечаем 200 — провайдеру не за чем ретраить.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

$rawBody = file_get_contents('php://input');

// РЕЖИМ ЗАХВАТА (диагностика формата ChatPush). Включается флагом CHATPUSH_DEBUG_CAPTURE=1 в .env.
// Пишет метод, query, заголовки и сырое тело в logs/chatpush-inbound-raw.log ДО любых проверок,
// чтобы поймать самый первый реальный колбэк (даже без секрета) и подогнать chatpushParse().
// ⚠️ Лог содержит PII (телефоны/тексты) — отключить флаг после настройки формата.
if (defined('CHATPUSH_DEBUG_CAPTURE') && CHATPUSH_DEBUG_CAPTURE) {
    $hdrs = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0 || in_array($k, ['CONTENT_TYPE', 'REQUEST_METHOD', 'QUERY_STRING'], true)) {
            $hdrs[$k] = $v;
        }
    }
    $dump = date('Y-m-d H:i:s') . " [ChatPush RAW]\n"
        . 'HEADERS: ' . json_encode($hdrs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        . 'BODY: ' . (string)$rawBody . "\n----\n";
    @file_put_contents(BASE_PATH . '/logs/chatpush-inbound-raw.log', $dump, FILE_APPEND | LOCK_EX);
}

if ($rawBody === false || $rawBody === '') {
    echo 'ok';
    exit;
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    error_log('[ChatPush webhook] тело не JSON-объект');
    echo 'ok';
    exit;
}

// Проверка секрета (fail-closed): без заданного секрета входящие отклоняем, чтобы публичный
// эндпоинт не дёргал YandexGPT и не плодил алерты по неаутентифицированным запросам.
$expectedSecret = defined('CHATPUSH_CALLBACK_SECRET') ? CHATPUSH_CALLBACK_SECRET : '';
if ($expectedSecret === '') {
    error_log('[ChatPush webhook] CHATPUSH_CALLBACK_SECRET не задан — входящие отклонены (fail-closed)');
    echo 'ok';
    exit;
}
$gotSecret = $_SERVER['HTTP_X_CHATPUSH_SECRET'] ?? ($_GET['secret'] ?? ($body['secret'] ?? ''));
if (!hash_equals($expectedSecret, (string)$gotSecret)) {
    error_log('[ChatPush webhook] неверный secret');
    echo 'ok';
    exit;
}

$parsed = chatpushParse($body);

try {
    if ($parsed['kind'] === 'status') {
        chatpushHandleStatus($db, $parsed);
        echo 'ok';
        exit;
    }

    if ($parsed['kind'] !== 'message') {
        error_log('[ChatPush webhook] неопознанный payload: ' . mb_substr($rawBody, 0, 300));
        echo 'ok';
        exit;
    }

    // Webhook отдаёт и входящие, и исходящие (эхо наших же отправок). ИИ отвечает только на входящие.
    if ($parsed['direction'] !== 'incoming') {
        echo 'ok';
        exit;
    }

    $phone = ChatpushClient::normalizePhone($parsed['phone']);
    if ($phone === null) {
        error_log('[ChatPush webhook] не удалось нормализовать телефон: ' . (string)$parsed['phone']);
        echo 'ok';
        exit;
    }

    $userId = chatpushFindUserId($db, $phone);

    require_once BASE_PATH . '/ai-consultant/src/AlertService.php';
    require_once BASE_PATH . '/ai-consultant/src/MaxInboundProcessor.php';

    $alertService = new AlertService($db);
    $processor    = new MaxInboundProcessor($db, $alertService);

    $receivedAt = $parsed['ts'] > 0 ? date('Y-m-d H:i:s', $parsed['ts']) : date('Y-m-d H:i:s');

    $result = $processor->process([
        'provider_message_id' => (string)$parsed['provider_message_id'],
        'phone'               => $phone,
        'user_id'             => $userId,
        'user_name'           => $parsed['from_name'],
        'text'                => (string)$parsed['text'],
        'received_at'         => $receivedAt,
    ]);

    // Телефон маскируем — не льём PII целиком в системный лог.
    $phoneMasked = mb_substr($phone, 0, 5) . '***' . mb_substr($phone, -2);
    error_log('[ChatPush webhook] phone=' . $phoneMasked . ' status=' . $result['status']
        . ' reply_sent=' . ($result['reply_sent'] ? '1' : '0')
        . ' alert_id=' . ($result['alert_id'] ?? '-'));
} catch (Throwable $e) {
    error_log('[ChatPush webhook] ошибка обработки: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

echo 'ok';
exit;

// ---------------------------------------------------------------------------

/**
 * Разобрать payload ChatPush (формат docs2.chatpush.ru) в унифицированную структуру.
 *
 * @return array{kind:string, direction:?string, phone:?string, text:?string,
 *   provider_message_id:?string, from_name:?string, status_id:?int, status_desc:?string, ts:int}
 */
function chatpushParse(array $b): array
{
    $blank = [
        'kind' => 'unknown', 'direction' => null, 'phone' => null, 'text' => null,
        'provider_message_id' => null, 'from_name' => null, 'status_id' => null, 'status_desc' => null, 'ts' => 0,
    ];

    // 1) Колбэк статуса доставки: объект delivery с status.status_id/description.
    if (isset($b['delivery']) && is_array($b['delivery'])) {
        $d = $b['delivery'];
        $st = is_array($d['status'] ?? null) ? $d['status'] : [];
        return array_merge($blank, [
            'kind'                => 'status',
            'provider_message_id' => isset($d['id']) ? (string)$d['id'] : null,
            'status_id'           => isset($st['status_id']) ? (int)$st['status_id'] : (isset($d['status_id']) ? (int)$d['status_id'] : null),
            'status_desc'         => $st['description'] ?? ($d['status_description'] ?? null),
            'phone'               => isset($d['phone']) ? (string)$d['phone'] : null,
        ]);
    }

    // 2) Событие сообщения: payload.new_message (max_incoming_msg / *_incoming_msg).
    $nm = $b['payload']['new_message'] ?? null;
    if (is_array($nm)) {
        $msg = is_array($nm['message'] ?? null) ? $nm['message'] : [];
        $tsRaw = $msg['timestamp'] ?? null;

        // Текст: для нетекстовых — ссылка на файл/подпись, чтобы тред и ИИ что-то видели.
        $text = (string)($msg['text'] ?? '');
        if ($text === '' && is_array($msg['file_data'] ?? null)) {
            $cap = (string)($msg['file_data']['caption'] ?? '');
            $url = (string)($msg['file_data']['download_url'] ?? '');
            $kind = (string)($msg['type'] ?? 'файл');
            $text = trim('[Вложение: ' . $kind . ($cap !== '' ? ' — ' . $cap : '') . ']' . ($url !== '' ? ' ' . $url : ''));
        }

        return array_merge($blank, [
            'kind'                => 'message',
            'direction'           => (string)($nm['direction'] ?? 'incoming'),
            'phone'               => (string)($nm['chat_phone'] ?? ($nm['sender_phone_number'] ?? '')),
            'text'                => $text,
            'provider_message_id' => isset($msg['id']) ? (string)$msg['id'] : null,
            'from_name'           => $nm['sender_name'] ?? ($nm['pushname'] ?? null),
            'ts'                  => is_numeric($tsRaw) ? (int)$tsRaw : 0,
        ]);
    }

    return $blank;
}

/**
 * Обновить статус исходящего сообщения в ленте по delivery.id (== provider_message_id).
 * Маппинг по описанию статуса (числовая легенда status_id в доке не приведена полностью).
 */
function chatpushHandleStatus(PDO $db, array $parsed): void
{
    $msgId = $parsed['provider_message_id'];
    $desc  = mb_strtolower((string)($parsed['status_desc'] ?? ''));
    if ($msgId === null || $desc === '') {
        return;
    }

    // «доставлено/прочитано/принято/отправлено» → sent; «ошибка/запрещено/отклонено/не доставлено/истекло» → failed.
    $isFail = (bool)preg_match('/ошибк|запрещ|отклон|не\s*доставл|недостав|истек|отказ|заблок/u', $desc);
    $isOk   = (bool)preg_match('/доставл|прочит|принят|отправл|успеш/u', $desc);
    $mapped = $isFail ? 'failed' : ($isOk ? 'sent' : null);
    if ($mapped === null) {
        error_log('[ChatPush webhook] статус доставки без маппинга: ' . $desc);
        return;
    }

    $stmt = $db->prepare(
        "UPDATE max_messages SET `status` = ?, error = IF(? = 'failed', ?, error)
         WHERE provider_message_id = ? AND direction = 'out'"
    );
    $stmt->execute([$mapped, $mapped, $parsed['status_desc'], $msgId]);
    if ($stmt->rowCount() === 0) {
        error_log('[ChatPush webhook] статус для неизвестного delivery.id=' . $msgId);
    }
}

/**
 * Найти user_id по телефону. Сравнение по последним 10 цифрам (инвариант формата).
 */
function chatpushFindUserId(PDO $db, string $normalizedPhone): ?int
{
    $last10 = substr($normalizedPhone, -10);
    if (strlen($last10) < 10) {
        return null;
    }
    try {
        $stmt = $db->prepare(
            "SELECT id FROM users
             WHERE phone IS NOT NULL AND phone != ''
               AND RIGHT(REGEXP_REPLACE(phone, '[^0-9]', ''), 10) = ?
             ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([$last10]);
        $id = $stmt->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        error_log('[ChatPush webhook] поиск user по телефону упал: ' . $e->getMessage());
        return null;
    }
}

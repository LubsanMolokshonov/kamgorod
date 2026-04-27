<?php
/**
 * VK Callback API Webhook
 * Принимает события из сообщества ВКонтакте, создаёт алерты из входящих сообщений.
 *
 * Настройка в ВК: Управление → Работа с API → Callback API → добавить URL этого файла.
 * Включить событие: Сообщения → Входящие сообщения.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('BASE_PATH', dirname(__DIR__, 2));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';

// bootstrap.php читает ключи через getenv(), но у нас они в PHP-константах из .env
// Прокидываем перед загрузкой bootstrap
putenv('YANDEX_GPT_API_KEY=' . YANDEX_GPT_API_KEY);
putenv('YANDEX_GPT_FOLDER_ID=' . YANDEX_GPT_FOLDER_ID);
putenv('YANDEX_GPT_MODEL=' . (defined('YANDEX_GPT_MODEL') ? YANDEX_GPT_MODEL : 'yandexgpt-lite'));
putenv('DB_HOST=' . DB_HOST);
putenv('DB_NAME=' . DB_NAME);
putenv('DB_USER=' . DB_USER);
putenv('DB_PASS=' . DB_PASS);
putenv('SITE_URL=' . SITE_URL);

require_once BASE_PATH . '/ai-consultant/src/bootstrap.php';

// Всегда отвечать 200 — VK повторяет запрос при любом другом коде
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '') {
    echo 'ok';
    exit;
}

$body = json_decode($rawBody, true);
if (!is_array($body)) {
    echo 'ok';
    exit;
}

// Подтверждение адреса вебхука (первый запрос от ВК)
if (($body['type'] ?? '') === 'confirmation') {
    $confirmation = defined('VK_CONFIRMATION_STRING') ? VK_CONFIRMATION_STRING : '';
    if ($confirmation === '') {
        error_log('[VK webhook] VK_CONFIRMATION_STRING не задан');
        echo 'ok';
    } else {
        echo $confirmation;
    }
    exit;
}

// Проверка секретного ключа
$secret = defined('VK_CALLBACK_SECRET') ? VK_CALLBACK_SECRET : '';
if ($secret !== '' && ($body['secret'] ?? '') !== $secret) {
    error_log('[VK webhook] Неверный secret_key');
    echo 'ok';
    exit;
}

// Обрабатываем только новые входящие сообщения
$eventType = $body['type'] ?? '';
error_log('[VK webhook] Получен тип события: ' . $eventType);
if ($eventType !== 'message_new') {
    echo 'ok';
    exit;
}

$msgObj = $body['object']['message'] ?? $body['object'] ?? null;
if (!is_array($msgObj)) {
    error_log('[VK webhook] msgObj не массив');
    echo 'ok';
    exit;
}

$fromId  = (int)($msgObj['from_id'] ?? 0);
$peerId  = (int)($msgObj['peer_id'] ?? $fromId);
$msgId   = (int)($msgObj['id'] ?? 0);
$text    = trim((string)($msgObj['text'] ?? ''));

// Игнорировать исходящие (from_id < 0 — это группа/бот) и сообщения без ID
if ($fromId <= 0 || $msgId <= 0) {
    error_log('[VK webhook] Пропуск: fromId=' . $fromId . ' msgId=' . $msgId);
    echo 'ok';
    exit;
}

// Дедуп: уже обрабатывали это сообщение?
try {
    $stmt = $db->prepare('SELECT id FROM inbound_vk_log WHERE vk_message_id = ? LIMIT 1');
    $stmt->execute([$msgId]);
    if ($stmt->fetch()) {
        error_log('[VK webhook] Дедуп: message_id=' . $msgId . ' уже обработан');
        echo 'ok';
        exit;
    }
} catch (Throwable $e) {
    error_log('[VK webhook] Ошибка дедупа: ' . $e->getMessage());
    echo 'ok';
    exit;
}

// Обработку запускаем асинхронно, чтобы ответить ВКонтакте как можно быстрее.
// Используем register_shutdown_function — ответ уже отправлен, тело выполняется после.
$vkEvent = [
    'message_id' => $msgId,
    'peer_id'    => $peerId,
    'from_id'    => $fromId,
    'text'       => $text,
    'date'       => (int)($msgObj['date'] ?? time()),
];

// Основная обработка (синхронно — VK ждёт до 5 сек, YandexGPT укладывается в 3-4 сек)
try {
    error_log('[VK webhook] Обработка message_id=' . $vkEvent['message_id'] . ' from_id=' . $vkEvent['from_id'] . ' text=' . mb_substr($vkEvent['text'], 0, 80));

    require_once BASE_PATH . '/ai-consultant/src/AlertService.php';
    require_once BASE_PATH . '/ai-consultant/src/VkInboundProcessor.php';

    $alertService = new AlertService($db);
    $processor    = new VkInboundProcessor($db, $alertService);
    $result = $processor->process($vkEvent);

    error_log('[VK webhook] Результат: classification=' . $result['classification'] . ' reason=' . ($result['reason'] ?? '-') . ' alert_id=' . ($result['alert_id'] ?? '-'));
} catch (Throwable $e) {
    error_log('[VK webhook] Ошибка обработки message_id=' . $vkEvent['message_id'] . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
}

echo 'ok';

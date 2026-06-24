<?php
/**
 * Разовая настройка приёма входящих из мессенджера «Макс» (ChatPush).
 *
 * ChatPush не имеет поля callback-URL в кабинете — webhook на входящие регистрируется через API.
 * Этот скрипт регистрирует наш эндпоинт api/webhook/chatpush.php на события max_incoming_msg
 * (и max_bot_incoming_msg), вшивая секрет прямо в URL (?secret=…), т.к. отдельного поля секрета нет.
 *
 * Запуск (в контейнере):
 *   docker exec pedagogy_web php scripts/chatpush-register-webhook.php list      # показать текущие
 *   docker exec pedagogy_web php scripts/chatpush-register-webhook.php register  # создать webhook
 *   docker exec pedagogy_web php scripts/chatpush-register-webhook.php delete 123 # удалить по id
 *
 * Требует в .env: CHATPUSH_API_TOKEN, CHATPUSH_CALLBACK_SECRET, SITE_URL.
 * Документация: https://docs2.chatpush.ru
 */

require_once __DIR__ . '/../config/config.php';

$apiBase = rtrim(defined('CHATPUSH_API_URL') ? str_replace('/delivery', '', CHATPUSH_API_URL) : 'https://api.chatpush.ru/api/v1', '/');
$token   = defined('CHATPUSH_API_TOKEN') ? CHATPUSH_API_TOKEN : '';
$secret  = defined('CHATPUSH_CALLBACK_SECRET') ? CHATPUSH_CALLBACK_SECRET : '';
$site    = defined('SITE_URL') ? SITE_URL : '';

$cmd = $argv[1] ?? 'help';

if ($token === '') {
    fwrite(STDERR, "ERROR: CHATPUSH_API_TOKEN не задан в .env\n");
    exit(1);
}

/** Простой cURL-вызов к API ChatPush. */
function cp_api(string $method, string $url, string $token): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST   => $method,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_TIMEOUT         => 20,
        CURLOPT_HTTPHEADER      => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $raw  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return ['code' => $code, 'body' => (string)$raw, 'error' => $err];
}

switch ($cmd) {
    case 'list':
        $r = cp_api('GET', $apiBase . '/webhooks/', $token);
        echo "HTTP {$r['code']}\n{$r['body']}\n";
        break;

    case 'register':
        if ($secret === '' || $site === '') {
            fwrite(STDERR, "ERROR: нужны CHATPUSH_CALLBACK_SECRET и SITE_URL в .env\n");
            exit(1);
        }
        $callback = rtrim($site, '/') . '/api/webhook/chatpush.php?secret=' . urlencode($secret);
        // types[] — массив событий; формируем вручную, чтобы был именно types[]=...
        $query = 'url=' . urlencode($callback)
               . '&types[]=' . urlencode('max_incoming_msg')
               . '&types[]=' . urlencode('max_bot_incoming_msg');
        $r = cp_api('POST', $apiBase . '/webhooks?' . $query, $token);
        echo "Регистрируем callback: {$callback}\n";
        echo "HTTP {$r['code']}\n{$r['body']}\n";
        if ($r['code'] >= 200 && $r['code'] < 300) {
            echo "\nГOTOВО. Проверьте: php scripts/chatpush-register-webhook.php list\n";
        }
        break;

    case 'delete':
        $id = (int)($argv[2] ?? 0);
        if ($id <= 0) { fwrite(STDERR, "Укажите id: ... delete <id>\n"); exit(1); }
        $r = cp_api('DELETE', $apiBase . '/webhooks/' . $id, $token);
        echo "HTTP {$r['code']}\n{$r['body']}\n";
        break;

    default:
        echo "Использование:\n"
           . "  php scripts/chatpush-register-webhook.php list\n"
           . "  php scripts/chatpush-register-webhook.php register\n"
           . "  php scripts/chatpush-register-webhook.php delete <id>\n";
}

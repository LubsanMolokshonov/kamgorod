<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/lib/vk-blog/oauth.php';
require_once __DIR__ . '/lib/vk-blog/client.php';
$options = getopt('', ['begin', 'exchange', 'check', 'help']);
try {
    if (count(array_intersect(array_keys($options), ['begin', 'exchange', 'check'])) !== 1) {
        echo "VK ID PKCE: --begin (ссылка); --exchange (JSON через stdin); --check (права, без публикации)\n";
        exit(isset($options['help']) || !$options ? 0 : 1);
    }
    $oauth = new VkBlogOAuth(VK_BLOG_OAUTH_DIR, (int)VK_APP_ID, VK_BLOG_REDIRECT_URI);
    if (isset($options['begin'])) { echo $oauth->begin() . "\n"; exit(0); }
    if (isset($options['exchange'])) {
        $input = json_decode((string)stream_get_contents(STDIN, 16384), true);
        if (!is_array($input)) { throw new RuntimeException('Передайте JSON с code, device_id, state через stdin'); }
        $oauth->exchange((string)($input['code'] ?? ''), (string)($input['device_id'] ?? ''), (string)($input['state'] ?? ''));
        echo "Токены сохранены в закрытом серверном хранилище\n";
    }
    (new VkBlogClient('', (int)VK_BLOG_GROUP_ID, $oauth))->preflight();
    echo "Права VK проверены; посты и фотографии не создавались\n";
} catch (Throwable $error) {
    // No trace, raw provider payloads or input values.
    fwrite(STDERR, $error instanceof VkBlogApiError ? 'VK API error ' . $error->vkCode . "\n" :
        ($error instanceof RuntimeException ? $error->getMessage() : 'Ошибка VK OAuth') . "\n");
    exit(1);
}
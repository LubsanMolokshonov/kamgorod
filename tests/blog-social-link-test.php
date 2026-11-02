<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

require_once __DIR__ . '/../classes/BlogSocialLink.php';

function socialLinkCheck(bool $ok, string $label): void
{
    if (!$ok) { throw new RuntimeException($label); }
    echo "OK: $label\n";
}

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$db->exec('CREATE TABLE publications (id INTEGER PRIMARY KEY, slug TEXT, source TEXT, status TEXT, published_at TEXT, redirect_to_slug TEXT)');
$add = $db->prepare('INSERT INTO publications VALUES (?,?,?,?,?,?)');
foreach ([
    [1, 'first', 'blog', 'published', '2020-01-01 12:00:00', null],
    [2, 'second', 'blog', 'published', '2020-01-01 12:00:00', 'first'],
    [3, 'draft', 'blog', 'draft', '2020-01-01 12:00:00', null],
    [4, 'future', 'blog', 'published', '2999-01-01 12:00:00', null],
    [5, 'user-article', 'user', 'published', '2020-01-01 12:00:00', null],
    [6, 'cycle-a', 'blog', 'published', '2020-01-01 12:00:00', 'cycle-b'],
    [7, 'cycle-b', 'blog', 'published', '2020-01-01 12:00:00', 'cycle-a'],
    [8, 'missing-target', 'blog', 'published', '2020-01-01 12:00:00', 'missing'],
    [9, 'draft-target', 'blog', 'published', '2020-01-01 12:00:00', 'draft'],
    [10, 'external-target', 'blog', 'published', '2020-01-01 12:00:00', 'https://example.org/'],
    [11, 'not-dated', 'blog', 'published', null, null],
] as $row) { $add->execute($row); }

socialLinkCheck(BlogSocialLink::url('tg', 1) === 'https://fgos.pro/s/tg/1', 'Короткая ссылка Telegram');
socialLinkCheck(BlogSocialLink::url('vk', 1, 'https://www.fgos.pro/') === 'https://fgos.pro/s/vk/1', 'Единый публичный домен');
foreach ([['mail',1,'https://fgos.pro'], ['tg',0,'https://fgos.pro'], ['vk',1,'https://evil.test'],
    ['vk',1,"https://fgos.pro\r\nLocation: https://evil.test"], ['vk',1,'https://user@fgos.pro'],
    ['vk',1,'https://fgos.pro/?url=https://evil.test'], ['vk',1,'http://fgos.pro']] as $args) {
    $rejected = false;
    try { BlogSocialLink::url(...$args); } catch (InvalidArgumentException $e) { $rejected = true; }
    socialLinkCheck($rejected, 'Недопустимый источник, ID или origin отклонён');
}
socialLinkCheck(BlogSocialLink::parsePath('/s/tg/1?source=vk&publication_id=2&url=https://evil.test') === ['source'=>'tg','publication_id'=>1], 'Query не подменяет параметры пути');
socialLinkCheck(BlogSocialLink::parsePath('/s/vk/2/') === ['source'=>'vk','publication_id'=>2], 'Необязательный завершающий слеш');
foreach (['/s/other/1', '/s/tg/0', '/s/tg/-1', '/s/tg/01', '/s/tg/1e3', '/s/tg/1/extra', '/s/tg/%31',
    '/s/tg/9999999999999999999999999', '//s/tg/1', '/pages/blog-social-redirect.php?source=tg&publication_id=1'] as $path) {
    socialLinkCheck(BlogSocialLink::parsePath($path) === null, 'Некорректный путь отклонён: ' . $path);
}

$changes = $db->query('SELECT total_changes()')->fetchColumn();
socialLinkCheck(BlogSocialLink::resolve($db, 'tg', 1) === 'https://fgos.pro/blog/first/?utm_source=telegram&utm_campaign=blog', 'Telegram UTM в конечном URL');
socialLinkCheck(BlogSocialLink::resolve($db, 'vk', 2) === 'https://fgos.pro/blog/first/?utm_source=vk&utm_campaign=blog', 'Редирект статьи разрешён до конечного URL с VK UTM');
foreach ([3,4,5,6,7,8,9,10,11,999] as $id) {
    socialLinkCheck(BlogSocialLink::resolve($db, 'vk', $id) === null, 'Недоступная статья/цепочка не перенаправляется: ' . $id);
}
socialLinkCheck(BlogSocialLink::resolve($db, 'other', 1) === null, 'Неизвестный источник не разрешается');
socialLinkCheck($db->query('SELECT total_changes()')->fetchColumn() === $changes, 'Разрешение коротких ссылок не изменяет БД');
$db->exec("UPDATE publications SET slug='renamed' WHERE id=1");
socialLinkCheck(BlogSocialLink::resolve($db, 'tg', 1) === 'https://fgos.pro/blog/renamed/?utm_source=telegram&utm_campaign=blog', 'Смена slug не ломает ссылку по ID');
$moscowPublished = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s');
$add->execute([12, 'moscow-published', 'blog', 'published', $moscowPublished, null]);
socialLinkCheck(BlogSocialLink::resolve($db, 'tg', 12) !== null, 'Публикация по московскому времени не задерживается на три часа');
for ($id=100; $id<=120; $id++) {
    $add->execute([$id, 'chain-' . $id, 'blog', 'published', '2020-01-01 12:00:00', $id<120 ? 'chain-' . ($id+1) : null]);
}
socialLinkCheck(BlogSocialLink::resolve($db, 'vk', 100) === null, 'Слишком длинная цепочка ограничена');

// HTTP-проверки без рабочей конфигурации/БД: заведомо закрытый порт локальной MySQL.
$dir = sys_get_temp_dir() . '/fgos-social-link-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
$router = $dir . '/router.php';
$endpoint = realpath(__DIR__ . '/../pages/blog-social-redirect.php');
file_put_contents($router, '<?php define("CONFIG_LOADED",true); define("DB_HOST","127.0.0.1;port=1"); define("DB_NAME","unused"); define("DB_USER","unused"); define("DB_PASS",""); define("DB_CHARSET","utf8mb4"); require ' . var_export($endpoint,true) . ';');
$listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($listener === false) { throw new RuntimeException('Нет локального порта для HTTP-теста'); }
$address = stream_socket_get_name($listener, false);
fclose($listener);
$proc = null;
try {
    $proc = proc_open([PHP_BINARY, '-d', 'error_log=/dev/null', '-S', $address, $router],
        [0=>['pipe','r'],1=>['file','/dev/null','w'],2=>['file','/dev/null','w']], $pipes, $dir);
    if (!is_resource($proc)) { throw new RuntimeException('Не запущен изолированный HTTP-сервер'); }
    fclose($pipes[0]);
    $ready = false;
    for ($i=0; $i<100; $i++) {
        $socket = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($socket) { fclose($socket); $ready=true; break; }
        usleep(20000);
    }
    if (!$ready) { throw new RuntimeException('Изолированный HTTP-сервер не ответил'); }
    foreach ([['/s/other/1','GET',404], ['/s/tg/0','GET',404], ['/pages/blog-social-redirect.php?source=tg&publication_id=1','GET',404],
        ['/s/tg/1','POST',405], ['/s/tg/1?source=vk&publication_id=999','GET',503], ['/s/vk/1','HEAD',503]] as [$path,$method,$expected]) {
        $context = stream_context_create(['http'=>['method'=>$method,'ignore_errors'=>true,'timeout'=>7,'follow_location'=>0]]);
        $body = file_get_contents('http://' . $address . $path, false, $context);
        $headers = $http_response_header;
        socialLinkCheck(str_contains($headers[0], ' ' . $expected . ' '), 'HTTP ' . $expected . ': ' . $method . ' ' . $path);
        $all = strtolower(implode("\n", $headers));
        socialLinkCheck(str_contains($all,'cache-control: no-store') && str_contains($all,'x-robots-tag: noindex, nofollow'), 'HTTP запрещает кэширование и индексацию');
        socialLinkCheck($body === '' && !str_contains($all,'set-cookie:') && !str_contains($all,'location:'), 'Ошибки без внутренних деталей, сессии и перенаправления');
    }
} finally {
    if (is_resource($proc)) { proc_terminate($proc); proc_close($proc); }
    unlink($router);
    rmdir($dir);
}
echo "Проверки коротких ссылок блога пройдены\n";

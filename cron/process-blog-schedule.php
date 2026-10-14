<?php
/** Отложенная публикация блога: по умолчанию dry-run, запись только с --publish. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../scripts/lib/blog-schedule.php';
$lock = null;
try {
    $options = getopt('', ['schedule:', 'publish', 'validate-only']);
    if (empty($options['schedule']) || (isset($options['publish'], $options['validate-only']))) {
        throw new InvalidArgumentException('Укажите --schedule=editorial/schedules/имя.json; --publish или --validate-only необязательны.');
    }
    $root = dirname(__DIR__);
    $file = $root . '/' . $options['schedule'];
    $entries = blogScheduleLoad($root, $file);
    if (isset($options['validate-only'])) { echo 'Проверено комплектов: ' . count($entries) . "; БД не подключалась.\n"; exit(0); }
    $lock = fopen(sys_get_temp_dir() . '/fgos-blog-schedule-' . hash('sha256', realpath($file)) . '.lock', 'c');
    if (!$lock) { throw new RuntimeException('Не удалось открыть блокировку.'); }
    if (!flock($lock, LOCK_EX | LOCK_NB)) { echo "Расписание уже обрабатывается.\n"; exit(0); }
    require_once $root . '/config/config.php';
    require_once $root . '/classes/Database.php';
    require_once $root . '/classes/Publication.php';
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET,
        DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
    $results = blogScheduleProcess($pdo, $entries, new DateTimeImmutable('now'), isset($options['publish']));
    echo json_encode(['checked_at' => date(DATE_ATOM), 'mode' => isset($options['publish']) ? 'publish' : 'dry-run', 'entries' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка расписания блога: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}

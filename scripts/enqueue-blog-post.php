<?php
/** Поставить готовую статью в конец очереди; по умолчанию только показать дату. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/lib/blog-queue.php';
try {
    $options = getopt('', ['manifest:', 'enqueue', 'approved-sha256:', 'help']);
    if (isset($options['help']) || empty($options['manifest'])) {
        echo "php scripts/enqueue-blog-post.php --manifest=editorial/articles/<slug>/metadata.json [--enqueue --approved-sha256=<hash>]\n";
        exit(isset($options['help']) ? 0 : 1);
    }
    if (!preg_match('~^editorial/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/metadata\.json$~D', $options['manifest'], $match)) {
        throw new InvalidArgumentException('Неверный путь manifest.');
    }
    $process = proc_open([PHP_BINARY, __DIR__ . '/editorial-check.php', '--article=' . $match[1]],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Не удалось запустить проверку комплекта.'); }
    $check = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Комплект не прошёл editorial-check: ' . trim($check)); }
    $result = blogQueueAppend(dirname(__DIR__), $options['manifest'], new DateTimeImmutable('now'),
        isset($options['enqueue']), $options['approved-sha256'] ?? null);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка очереди блога: ' . $e->getMessage() . "\n"); exit(1);
}

<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

// Запускаем настоящую CLI-команду с подставными БД и Telegram в закрытом temp-каталоге.
// config/database.php намеренно перезаписывает $options, как в рабочем проекте.
$root = sys_get_temp_dir() . '/fgos-tg-cli-' . bin2hex(random_bytes(8));
$files = [];
$write = static function(string $name, string $content) use ($root, &$files): void {
    $path = $root . '/' . $name;
    if (!is_dir(dirname($path))) { mkdir(dirname($path),0700,true); }
    file_put_contents($path,$content); $files[]=$path;
};
try {
    $write('cron/publish-blog-telegram.php', file_get_contents(__DIR__ . '/../cron/publish-blog-telegram.php'));
    $write('config/config.php', '<?php $_ENV["TG_BLOG_ENABLED"]="true"; define("PUBLIC_SITE_URL","https://fgos.pro");');
    $write('config/database.php', <<<'PHP'
<?php
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION];
class CliStatement {
    public function execute(array $p): void {}
    public function fetchColumn(): int { return 1; }
    public function fetchAll(int $mode): array { return [['id'=>775,'slug'=>'without-cover','cover_image_url'=>null]]; }
}
// Не используем PDO: предпросмотр и check-site не требуют его методов подготовки.
$db = new class {
    public function query(string $sql): CliStatement { return new CliStatement(); }
    public function prepare(string $sql): CliStatement { return new CliStatement(); }
};
PHP);
    $write('scripts/lib/tg-blog/publisher.php', <<<'PHP'
<?php
class TgBlogClient { public function __construct(...$args) {} public function preflight(): string { return '-10042'; } }
class TgBlogSite { public function __construct(...$args) {} }
class TgBlogPublisher {
    public function __construct(...$args) {}
    public function preview(): array { return ['mode'=>'preview']; }
    public function initialize(): void { echo "INITIALIZED\n"; }
    public function run(string $queue): array { return ['mode'=>'send','queue'=>$queue]; }
}
PHP);
    $run = static function(string $arguments) use ($root): array {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/cron/publish-blog-telegram.php') . ' ' . $arguments;
        $process = proc_open($command,[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if (!is_resource($process)) { throw new RuntimeException('Cannot launch CLI'); }
        $out=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        return [proc_close($process),$out,$err];
    };
    foreach ([['--check-site', 'SKIP: 775'], ['--preview','"mode": "preview"'],
        ['--initialize','INITIALIZED'], ['--send --queue=new','"queue":"new"']] as [$args,$expected]) {
        [$code,$out,$err]=$run($args);
        if ($code!==0 || !str_contains($out,$expected)) { throw new RuntimeException("CLI $args failed: $out $err"); }
        echo "OK: CLI $args preserves mode after database config\n";
    }
    [$code]=$run('--preview --initialize');
    if ($code!==1) { throw new RuntimeException('Conflicting modes accepted'); }
    echo "OK: conflicting CLI modes rejected\n";
} finally {
    foreach (array_reverse($files) as $file) { unlink($file); }
    foreach (['scripts/lib/tg-blog','scripts/lib','scripts','cron','config',''] as $dir) {
        if (is_dir($root . '/' . $dir)) { rmdir($root . '/' . $dir); }
    }
}

<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

// Проверяем тот же загрузчик .env, который используется web и cron, с синтетическими данными.
$dir = sys_get_temp_dir() . '/checkout-flags-' . bin2hex(random_bytes(8));
mkdir($dir . '/config', 0700, true);
copy(__DIR__ . '/../config/config.php', $dir . '/config/config.php');
try {
    foreach (['false' => 'off', 'true' => 'on'] as $value => $expected) {
        file_put_contents($dir . '/.env', "APP_ENV=local\nUPLOADS_DIR=$dir\nTEMPLATES_DIR=$dir\nTHUMBNAILS_DIR=$dir\nEMAIL_SENDING_ENABLED=$value\nSUBSCRIPTION_AUTORENEW_ENABLED=$value\n");
        $run = proc_open([PHP_BINARY, '-r', 'require $argv[1]; echo json_encode([EMAIL_SENDING_ENABLED,SUBSCRIPTION_AUTORENEW_ENABLED]);',
                          $dir . '/config/config.php'], [1 => ['pipe', 'w']], $pipes);
        $flags = json_decode(stream_get_contents($pipes[1]), true);
        fclose($pipes[1]);
        if (proc_close($run) !== 0 || $flags !== [$expected === 'on', $expected === 'on']) {
            throw new RuntimeException('Рубильники из .env должны быть булевыми значениями');
        }
    }
    echo "OK: реальные значения рубильников писем и автопродления из .env\n";
} finally {
    unlink($dir . '/.env');
    unlink($dir . '/config/config.php');
    rmdir($dir . '/config');
    rmdir($dir);
}

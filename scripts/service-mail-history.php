<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

/** Только SELECT и IMAP-чтение. Пример: php scripts/service-mail-history.php --alert-id=123 */
ini_set('display_errors', '0');
require_once __DIR__ . '/../config/config.php';
ini_set('display_errors', '0');
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../classes/ServiceHistoryImapReader.php';

$options = getopt('', ['alert-id:', 'probe']);
$emit = static function (array $result): void {
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT) . PHP_EOL;
};
$reader = new ServiceHistoryImapReader(ServiceHistoryImapReader::settings());
$settings = ServiceHistoryImapReader::settings();
$exitCode = 0;
$alarm = function_exists('pcntl_alarm') && function_exists('pcntl_async_signals');
if ($alarm) {
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function (): void { throw new RuntimeException('history_timeout'); });
    pcntl_alarm(60);
}
try {
    if (isset($options['probe'])) {
        // Явная диагностика до включения флага, без чтения писем и подключения к БД.
        $folders = $reader->folders();
        $included = array_values(array_filter($folders, static fn(array $folder): bool => !ServiceMailHistory::excludedFolder($folder)));
        $reader->close();
        $emit(['status' => 'ok', 'enabled' => $settings['enabled'], 'folders_total' => count($folders),
            'folders_included' => array_column($included, 'name'), 'folders_excluded' => count($folders) - count($included)]);
    } else {
        $id = filter_var($options['alert-id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$id) { throw new InvalidArgumentException('invalid_alert_id'); }
        if (!$settings['enabled']) {
            $result = (new ServiceMailHistory($reader, false))->lookup('');
        } else {
            // Собственное подключение: ошибки не должны раскрывать DSN, пользователя или пароль.
            $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]);
            $query = $pdo->prepare('SELECT user_email FROM support_alerts WHERE id = ?');
            $query->execute([$id]);
            $email = $query->fetchColumn();
            if ($email === false) { throw new InvalidArgumentException('alert_not_found'); }
            $result = (new ServiceMailHistory($reader, true))->lookup((string)$email);
        }
        $result['alert_id'] = $id;
        $emit($result);
    }
} catch (Throwable $e) {
    $reason = $e instanceof InvalidArgumentException ? $e->getMessage()
        : ($e->getMessage() === 'history_timeout' ? 'timeout' : 'history_unavailable');
    $emit(['status' => 'unavailable', 'complete' => false, 'no_matches' => null, 'messages' => [], 'reasons' => [$reason]]);
    $exitCode = 1;
} finally {
    if ($alarm) { pcntl_alarm(0); }
    try { $reader->close(); } catch (Throwable $ignored) { /* Не выводим сообщения библиотеки с credentials. */ }
}
exit($exitCode);

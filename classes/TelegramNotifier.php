<?php
/**
 * TelegramNotifier — отправка технических алертов в Telegram-бот.
 *
 * Использует тот же бот, что и ИИ-консультант (константы TELEGRAM_BOT_TOKEN / TELEGRAM_ALERT_CHAT_ID,
 * с fallback на AI_TELEGRAM_BOT_TOKEN / AI_TELEGRAM_ALERT_CHAT_ID).
 *
 * Дедупликация: в течение dedup_ttl секунд (по умолчанию 600) алерт с одним и тем же $key
 * не отправляется повторно — запись в `telegram_alert_log` служит rate-limit флагом.
 *
 * Не блокирует основной поток: все ошибки cURL/БД проглатываются (error_log), возвращается false.
 */
class TelegramNotifier
{
    private const DEFAULT_DEDUP_TTL = 600; // 10 минут

    /** @var array<string,int> Переопределение TTL для конкретных ключей (в секундах) */
    private const DEDUP_TTL_OVERRIDES = [
        'smtp_send_failure'            => 300,
        'course_email_mass_failures'   => 1800,
        'webinar_email_mass_failures'  => 1800,
        'publication_email_mass_failures' => 1800,
        'autowebinar_email_mass_failures' => 1800,
        'journey_email_mass_failures'  => 1800,
        'olympiad_email_mass_failures' => 1800,
    ];

    private static ?TelegramNotifier $instance = null;

    private ?PDO $pdo = null;
    private string $token;
    /** @var string[] */
    private array $chatIds = [];
    private bool $logTableExists;

    public static function instance(?PDO $pdo = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($pdo);
        } elseif ($pdo !== null && self::$instance->pdo === null) {
            self::$instance->pdo = $pdo;
            self::$instance->logTableExists = self::$instance->checkLogTableExists();
        }
        return self::$instance;
    }

    private function __construct(?PDO $pdo)
    {
        $this->pdo = $pdo ?: $this->resolvePdo();

        $token = defined('TELEGRAM_BOT_TOKEN') ? (string) TELEGRAM_BOT_TOKEN : '';
        if ($token === '' && defined('AI_TELEGRAM_BOT_TOKEN')) {
            $token = (string) AI_TELEGRAM_BOT_TOKEN;
        }
        $this->token = $token;

        $chatRaw = defined('TELEGRAM_ALERT_CHAT_ID') ? (string) TELEGRAM_ALERT_CHAT_ID : '';
        if ($chatRaw === '' && defined('AI_TELEGRAM_ALERT_CHAT_ID')) {
            $chatRaw = (string) AI_TELEGRAM_ALERT_CHAT_ID;
        }
        $this->chatIds = array_values(array_filter(
            array_map('trim', explode(',', $chatRaw)),
            static fn($v) => $v !== ''
        ));

        $this->logTableExists = $this->checkLogTableExists();
    }

    /**
     * Отправить алерт в Telegram.
     *
     * @param string $key       Ключ для дедупликации (например, 'smtp_send_failure', 'cron_fatal_process-course-emails')
     * @param string $title     Заголовок (попадёт в <b>…</b>)
     * @param array  $context   Произвольный ассоциативный массив с деталями
     * @param string $severity  'info' | 'warning' | 'critical' (влияет на иконку)
     * @return bool true — отправлено, false — пропущено из-за rate-limit / не настроено / ошибка
     */
    public function alert(string $key, string $title, array $context = [], string $severity = 'critical'): bool
    {
        if ($this->token === '' || empty($this->chatIds)) {
            return false;
        }

        $ttl = self::DEDUP_TTL_OVERRIDES[$key] ?? self::DEFAULT_DEDUP_TTL;
        if ($this->isDuplicate($key, $ttl)) {
            return false;
        }

        $text = $this->buildMessage($title, $context, $severity);
        $httpCode = 0;
        $anySent = false;

        foreach ($this->chatIds as $chatId) {
            $httpCode = $this->send($chatId, $text);
            if ($httpCode === 200) {
                $anySent = true;
            }
        }

        $this->recordSent($key, $title, $context, $severity, $httpCode);

        // Вероятностный GC (1% запусков) — чистим записи старше 30 дней
        if ($this->logTableExists && $this->pdo !== null && mt_rand(1, 100) === 1) {
            try {
                $this->pdo->exec("DELETE FROM telegram_alert_log WHERE sent_at < NOW() - INTERVAL 30 DAY");
            } catch (\Throwable $e) {
                // ignore
            }
        }

        return $anySent;
    }

    /**
     * Пороговая проверка массовых падений email-отправки.
     * Вызывать в конце каждого cron-скрипта email-цепочки.
     *
     * @param string $table      Таблица лога (course_email_log / email_journey_log / ...)
     * @param string $alertKey   Ключ алерта для TelegramNotifier (по нему идёт дедуп)
     * @param string $label      Человекочитаемое имя цепочки (для заголовка сообщения)
     * @param int    $threshold  Порог (>= N неудач за окно → алерт)
     * @param int    $windowMin  Окно в минутах
     */
    public function checkEmailFailureThreshold(
        string $table,
        string $alertKey,
        string $label,
        int $threshold = 10,
        int $windowMin = 15
    ): void {
        if ($this->pdo === null) return;

        try {
            // Таблица может отсутствовать на окружении — проверяем безопасно
            $allowed = ['course_email_log', 'email_journey_log', 'publication_email_log', 'autowebinar_email_log', 'olympiad_email_log', 'webinar_email_log'];
            if (!in_array($table, $allowed, true)) return;

            $stmt = $this->pdo->prepare(
                "SELECT COUNT(*) AS cnt FROM `{$table}`
                 WHERE status = 'failed' AND created_at > NOW() - INTERVAL :win MINUTE"
            );
            $stmt->bindValue(':win', $windowMin, PDO::PARAM_INT);
            $stmt->execute();
            $cnt = (int) $stmt->fetchColumn();

            if ($cnt >= $threshold) {
                $this->alert(
                    $alertKey,
                    "[Email] Массовые падения: {$label}",
                    [
                        'failed_count' => $cnt,
                        'window_min'   => $windowMin,
                        'threshold'    => $threshold,
                        'table'        => $table,
                    ],
                    'critical'
                );
            }
        } catch (\Throwable $e) {
            error_log('TelegramNotifier::checkEmailFailureThreshold failed: ' . $e->getMessage());
        }
    }

    private function isDuplicate(string $key, int $ttl): bool
    {
        if (!$this->logTableExists || $this->pdo === null) return false;

        try {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM telegram_alert_log
                 WHERE alert_key = ? AND sent_at > NOW() - INTERVAL ? SECOND
                 LIMIT 1"
            );
            $stmt->execute([$key, $ttl]);
            return (bool) $stmt->fetchColumn();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function recordSent(string $key, string $title, array $context, string $severity, int $httpCode): void
    {
        if (!$this->logTableExists || $this->pdo === null) return;

        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO telegram_alert_log (alert_key, title, context, severity, http_code)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->execute([
                mb_substr($key, 0, 190),
                mb_substr($title, 0, 500),
                json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                in_array($severity, ['info', 'warning', 'critical'], true) ? $severity : 'critical',
                $httpCode ?: null,
            ]);
        } catch (\Throwable $e) {
            error_log('TelegramNotifier::recordSent failed: ' . $e->getMessage());
        }
    }

    private function buildMessage(string $title, array $context, string $severity): string
    {
        $icon = match ($severity) {
            'info'     => 'ℹ️',
            'warning'  => '⚠️',
            default    => '🔴',
        };
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $host = $_SERVER['HTTP_HOST'] ?? (function_exists('gethostname') ? gethostname() : 'unknown');
        if (defined('SITE_URL')) {
            $parsed = parse_url(SITE_URL, PHP_URL_HOST);
            if ($parsed) $host = $parsed;
        }

        $lines = [];
        $lines[] = $icon . ' <b>' . $esc($title) . '</b>';
        $lines[] = '<b>Когда:</b> ' . $esc(date('Y-m-d H:i:s'));
        $lines[] = '<b>Сервер:</b> ' . $esc((string) $host);
        if (defined('APP_ENV')) {
            $lines[] = '<b>Окружение:</b> ' . $esc((string) APP_ENV);
        }

        if (!empty($context)) {
            $lines[] = '';
            $lines[] = '<b>Контекст:</b>';
            foreach ($context as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $val = $v === null ? 'null' : (string) $v;
                } else {
                    $val = json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    if ($val === false) $val = '[unserializable]';
                }
                $val = mb_substr($val, 0, 600);
                $lines[] = '• <b>' . $esc((string) $k) . ':</b> <code>' . $esc($val) . '</code>';
            }
        }

        $text = implode("\n", $lines);
        // Telegram max = 4096 символов
        if (mb_strlen($text) > 3900) {
            $text = mb_substr($text, 0, 3900) . "\n…(truncated)";
        }
        return $text;
    }

    private function send(string $chatId, string $text): int
    {
        $url = 'https://api.telegram.org/bot' . $this->token . '/sendMessage';
        $payload = http_build_query([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);

        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_CONNECTTIMEOUT => 3,
                // Принудительно IPv4: у прод-хоста нет IPv6-маршрута,
                // а api.telegram.org резолвится в AAAA → иначе connect timeout.
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ]);
            $resp = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);

            if ($httpCode !== 200) {
                error_log(sprintf(
                    'TelegramNotifier send failed: chat=%s http=%d err=%s resp=%s',
                    $chatId, $httpCode, $err, substr((string) $resp, 0, 300)
                ));
            }
            return $httpCode;
        } catch (\Throwable $e) {
            error_log('TelegramNotifier send exception: ' . $e->getMessage());
            return 0;
        }
    }

    private function resolvePdo(): ?PDO
    {
        // Попытка переиспользовать $db из глобальной области (config/database.php)
        if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
            return $GLOBALS['db'];
        }

        // Fallback: собрать подключение из констант
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER')) {
            return null;
        }
        try {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            return new PDO($dsn, DB_USER, DB_PASS ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function checkLogTableExists(): bool
    {
        if ($this->pdo === null) return false;
        try {
            $this->pdo->query("SELECT 1 FROM telegram_alert_log LIMIT 1");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Установить shutdown-handler для ловли fatal errors в CLI-скриптах.
     * Вызывать в самом начале cron-файлов.
     */
    public static function registerFatalHandler(string $scriptName): void
    {
        register_shutdown_function(static function () use ($scriptName) {
            $err = error_get_last();
            if (!$err) return;
            $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_CORE_WARNING, E_COMPILE_ERROR, E_COMPILE_WARNING];
            if (!in_array($err['type'], $fatalTypes, true)) return;

            try {
                self::instance()->alert(
                    'cron_fatal_' . $scriptName,
                    '[Cron] Fatal error: ' . $scriptName,
                    [
                        'message' => (string)($err['message'] ?? ''),
                        'file'    => (string)($err['file'] ?? ''),
                        'line'    => (int)($err['line'] ?? 0),
                        'type'    => (int)($err['type'] ?? 0),
                    ],
                    'critical'
                );
            } catch (\Throwable $e) {
                // no-op
            }
        });
    }
}

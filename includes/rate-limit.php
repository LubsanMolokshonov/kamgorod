<?php
/**
 * Простой файловый rate-limiter для login-эндпоинтов (защита от брутфорса).
 *
 * Хранит таймстемпы неудачных попыток в файле в системном temp, под flock.
 * Не для распределённого кластера, но достаточно против перебора с одного IP.
 *
 * Использование:
 *   $key = 'admin_login_' . $_SERVER['REMOTE_ADDR'];
 *   if (rateLimitTooMany($key)) { отказ }
 *   ... при неуспехе: rateLimitRegisterFailure($key);
 *   ... при успехе:   rateLimitReset($key);
 */

function rateLimitFile(string $key): string {
    return sys_get_temp_dir() . '/login_rl_' . md5($key) . '.txt';
}

/**
 * Прочитать таймстемпы попыток в пределах окна (старые отбрасываются).
 */
function rateLimitAttempts(string $key, int $windowSeconds = 900): array {
    $file = rateLimitFile($key);
    if (!is_file($file)) {
        return [];
    }
    $raw = @file_get_contents($file);
    if ($raw === false || $raw === '') {
        return [];
    }
    $now = time();
    $attempts = array_map('intval', explode(',', $raw));
    return array_values(array_filter($attempts, static fn($t) => $t > $now - $windowSeconds));
}

/**
 * Превышен ли лимит неудачных попыток за окно.
 */
function rateLimitTooMany(string $key, int $maxAttempts = 5, int $windowSeconds = 900): bool {
    return count(rateLimitAttempts($key, $windowSeconds)) >= $maxAttempts;
}

/**
 * Зарегистрировать неудачную попытку (атомарно, под flock).
 */
function rateLimitRegisterFailure(string $key, int $windowSeconds = 900): void {
    rateLimitConsume($key, PHP_INT_MAX, $windowSeconds);
}

/** Проверить и занять место в окне под одной блокировкой. */
function rateLimitConsume(string $key, int $limit, int $windowSeconds): bool {
    $fp = @fopen(rateLimitFile($key), 'c+');
    if (!$fp) return false;
    try {
        if (!flock($fp, LOCK_EX)) return false;
        $raw = stream_get_contents($fp);
        $now = time();
        $attempts = $raw ? array_map('intval', explode(',', $raw)) : [];
        $attempts = array_filter($attempts, static fn($t) => $t > $now - $windowSeconds);
        if (count($attempts) >= $limit) return false;
        $attempts[] = $now;
        rewind($fp);
        $data = implode(',', $attempts);
        return ftruncate($fp, 0) && fwrite($fp, $data) === strlen($data) && fflush($fp);
    } finally {
        fclose($fp);
    }
}

/**
 * Сбросить счётчик (после успешного входа).
 */
function rateLimitReset(string $key): void {
    $file = rateLimitFile($key);
    if (is_file($file)) {
        @unlink($file);
    }
}

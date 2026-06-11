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
    $file = rateLimitFile($key);
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return;
    }
    try {
        if (flock($fp, LOCK_EX)) {
            $raw = stream_get_contents($fp);
            $now = time();
            $attempts = $raw ? array_map('intval', explode(',', $raw)) : [];
            $attempts = array_filter($attempts, static fn($t) => $t > $now - $windowSeconds);
            $attempts[] = $now;
            ftruncate($fp, 0);
            rewind($fp);
            fwrite($fp, implode(',', $attempts));
            fflush($fp);
            flock($fp, LOCK_UN);
        }
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

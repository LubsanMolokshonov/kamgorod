<?php
/** Добавление готового комплекта в хвост ежедневной очереди. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/blog-schedule.php';

function blogQueueDate($value): DateTimeImmutable {
    if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/D', $value)) {
        throw new InvalidArgumentException('Дата очереди должна содержать UTC offset.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
    if (!$date || $date->format('Y-m-d\TH:i:sP') !== $value) {
        throw new InvalidArgumentException('Некорректная дата очереди.');
    }
    return $date;
}

/** Один календарный день на статью, общий хвост учитывает прежние расписания. */
function blogQueueNextDate(array $dates, DateTimeImmutable $now): DateTimeImmutable {
    $tz = new DateTimeZone('Europe/Moscow');
    $slot = $now->setTimezone($tz)->setTime(9, 0, 0);
    if ($slot <= $now) { $slot = $slot->modify('+1 day'); }
    foreach ($dates as $date) {
        $after = $date->setTimezone($tz)->modify('+1 day')->setTime(9, 0, 0);
        if ($after > $slot) { $slot = $after; }
    }
    return $slot;
}

function blogQueueInlineAssets(string $root, array $package): array {
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="UTF-8">' . $package['content'], LIBXML_NONET);
    libxml_clear_errors(); libxml_use_internal_errors($previous);
    $assets = []; $imageRoot = realpath($root . '/assets/images/blog');
    foreach ($dom->getElementsByTagName('img') as $image) {
        $url = $image->getAttribute('src'); $path = realpath($root . $url);
        if (!$imageRoot || !$path || !str_starts_with($path, $imageRoot . '/') || !is_file($path)) {
            throw new RuntimeException('Иллюстрация отсутствует или вне каталога: ' . $url);
        }
        $assets[$url] = hash_file('sha256', $path);
    }
    ksort($assets);
    return $assets;
}

/** Не подключается к БД. --enqueue добавляет дату; cron публикует отдельно. */
function blogQueueAppend(string $root, string $manifest, DateTimeImmutable $now, bool $write, ?string $approved): array {
    $root = realpath($root);
    if (!$root || !preg_match('~^editorial/articles/([a-z0-9]+(?:-[a-z0-9]+)*)/metadata\.json$~D', $manifest, $match)) {
        throw new InvalidArgumentException('Укажите manifest внутри editorial/articles/<slug>/.');
    }
    $articleRoot = realpath($root . '/editorial/articles');
    $dir = realpath($root . '/editorial/articles/' . $match[1]);
    $scheduleRoot = realpath($root . '/editorial/schedules');
    if (!$articleRoot || !$dir || !str_starts_with($dir, $articleRoot . '/') || !$scheduleRoot) {
        throw new InvalidArgumentException('Каталог статьи или расписаний отсутствует.');
    }
    foreach (['brief.md', 'research.md', 'claims.md', 'sources.json', 'article.md', 'review.md', 'metadata.json'] as $file) {
        if (!is_file($dir . '/' . $file) || filesize($dir . '/' . $file) === 0) {
            throw new RuntimeException('Не завершён комплект: ' . $file);
        }
    }
    $input = json_decode(file_get_contents($dir . '/metadata.json'), true, 512, JSON_THROW_ON_ERROR);
    $content = realpath($dir . '/' . ($input['content-file'] ?? ''));
    if (!$content || !str_starts_with($content, $dir . '/')) {
        throw new InvalidArgumentException('HTML должен находиться внутри комплекта.');
    }
    $input['content-file'] = $content; $package = blogPostPackage($input, $root);
    if ($package['slug'] !== $match[1]) { throw new InvalidArgumentException('Slug не совпадает с manifest.'); }
    if ($write && (!$approved || !hash_equals($package['sha256'], $approved))) {
        throw new RuntimeException('Для постановки в очередь нужен текущий --approved-sha256.');
    }
    $assets = blogQueueInlineAssets($root, $package);
    $target = $scheduleRoot . '/daily-queue.json';
    $lock = null;
    try {
        if ($write) {
            $lock = fopen($scheduleRoot . '/.append.lock', 'c');
            if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Очередь меняется другим процессом; повторите добавление.');
            }
        }
        $dates = []; $existing = null;
        $data = ['version' => 1, 'timezone' => 'Europe/Moscow', 'publish_time' => '09:00',
            'approval' => 'Готовые комплекты добавляются по поручению пользователя от 16.10.2026.', 'entries' => []];
        foreach (glob($scheduleRoot . '/*.json') as $file) {
            if (is_link($file)) { throw new RuntimeException('Расписание не должно быть символической ссылкой.'); }
            $schedule = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            if (($schedule['version'] ?? null) !== 1 || !isset($schedule['entries']) || !is_array($schedule['entries'])) {
                throw new RuntimeException('Неверный файл расписания: ' . basename($file));
            }
            if ($file === $target) {
                $data = $schedule;
                if ($schedule['entries'] !== []) { blogScheduleLoad($root, $file); }
            }
            // Старые расписания читаются для дат и дубликатов, без повторного одобрения их комплектов.
            foreach ($schedule['entries'] as $entry) {
                $dates[] = blogQueueDate($entry['publish_at'] ?? null);
                if (($entry['manifest'] ?? '') !== $manifest) { continue; }
                if ($existing !== null) { throw new RuntimeException('Статья повторяется в нескольких расписаниях.'); }
                $listedAssets = $entry['inline_assets_sha256'] ?? []; ksort($listedAssets);
                if (($entry['approved_sha256'] ?? '') !== $package['sha256'] || $listedAssets !== $assets) {
                    throw new RuntimeException('Статья уже в очереди с другой версией; нужен отдельный пересмотр.');
                }
                $existing = ['status' => 'already_queued', 'manifest' => $manifest, 'slug' => $package['slug'],
                    'publish_at' => $entry['publish_at'], 'schedule' => 'editorial/schedules/' . basename($file),
                    'sha256' => $package['sha256']];
            }
        }
        if ($existing !== null) { return $existing; }
        if (count($data['entries']) >= 1000) { throw new RuntimeException('Очередь достигла 1000 записей; требуется архивирование.'); }
        $when = blogQueueNextDate($dates, $now)->format(DATE_ATOM);
        $data['entries'][] = ['manifest' => $manifest, 'publish_at' => $when,
            'approved_sha256' => $package['sha256'], 'inline_assets_sha256' => $assets];
        $data['updated_at'] = $now->format(DATE_ATOM);
        if ($write) {
            $tmp = tempnam($scheduleRoot, '.daily-queue-');
            if ($tmp === false) { throw new RuntimeException('Не удалось подготовить файл очереди.'); }
            try {
                $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
                if (file_put_contents($tmp, $json) !== strlen($json) || !chmod($tmp, 0644) || !rename($tmp, $target)) {
                    throw new RuntimeException('Не удалось атомарно сохранить очередь.');
                }
            } finally { if (is_file($tmp)) { unlink($tmp); } }
        }
        return ['status' => $write ? 'queued' : 'preview', 'manifest' => $manifest,
            'slug' => $package['slug'], 'publish_at' => $when,
            'schedule' => 'editorial/schedules/daily-queue.json', 'sha256' => $package['sha256']];
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
}

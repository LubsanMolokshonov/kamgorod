<?php
/** Локальный кэш и компактные карточки повторно используемых редакционных источников. */
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

$root = dirname(__DIR__);
$libraryDir = $root . '/editorial/source-library';
$catalogPath = $libraryDir . '/catalog.json';

function editorialSourceUsage(): void {
    echo "Использование:\n"
        . "  php scripts/editorial-source-library.php --list\n"
        . "  php scripts/editorial-source-library.php --show=SOURCE-ID\n"
        . "  php scripts/editorial-source-library.php --extract=SOURCE-ID --claim=CLAIM-ID\n"
        . "  php scripts/editorial-source-library.php --verify\n"
        . "  php scripts/editorial-source-library.php --refresh=SOURCE-ID\n";
}

function editorialSourceFind(array $catalog, string $id): array {
    foreach ($catalog['sources'] ?? [] as $source) {
        if (($source['id'] ?? '') === $id) {
            return $source;
        }
    }
    throw new InvalidArgumentException('Источник не найден: ' . $id);
}

function editorialSourceClaim(array $source, string $claimId): array {
    foreach ($source['claims'] ?? [] as $claim) {
        if (($claim['id'] ?? '') === $claimId) {
            return $claim;
        }
    }
    throw new InvalidArgumentException('Утверждение не найдено: ' . $claimId);
}

function editorialSourceUrlIsValid(string $url): bool {
    $parts = parse_url($url);
    return is_array($parts)
        && in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        && isset($parts['host'])
        && $parts['host'] !== '';
}

try {
    $options = getopt('', ['list', 'show:', 'extract:', 'claim:', 'verify', 'refresh:', 'help']);
    if (isset($options['help']) || $options === []) {
        editorialSourceUsage();
        exit(isset($options['help']) ? 0 : 1);
    }
    if (!is_file($catalogPath)) {
        throw new RuntimeException('Каталог источников отсутствует.');
    }
    $catalog = json_decode((string) file_get_contents($catalogPath), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($catalog['sources'] ?? null)) {
        throw new RuntimeException('В catalog.json отсутствует массив sources.');
    }

    if (isset($options['list'])) {
        foreach ($catalog['sources'] as $source) {
            printf("%s | %s | проверено %s\n", $source['id'], $source['title'], $source['checked_at']);
        }
        exit(0);
    }

    if (isset($options['show'])) {
        $source = editorialSourceFind($catalog, (string) $options['show']);
        echo json_encode($source, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if (isset($options['extract'])) {
        if (!isset($options['claim'])) {
            throw new InvalidArgumentException('Для --extract укажите --claim=CLAIM-ID.');
        }
        $source = editorialSourceFind($catalog, (string) $options['extract']);
        $claim = editorialSourceClaim($source, (string) $options['claim']);
        $result = [
            'source_id' => $source['id'],
            'title' => $source['title'],
            'url' => $source['url'],
            'edition' => $source['edition'] ?? null,
            'checked_at' => $source['checked_at'],
            'claim_id' => $claim['id'],
            'point' => $claim['point'],
            'scope' => $claim['scope'],
            'excerpt' => $claim['excerpt'],
            'evidence_file' => $claim['evidence_file'],
        ];
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        exit(0);
    }

    if (isset($options['verify'])) {
        $errors = [];
        $warnings = [];
        $ids = [];
        foreach ($catalog['sources'] as $source) {
            $id = $source['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[A-Z0-9-]+$/', $id) || isset($ids[$id])) {
                $errors[] = 'Некорректный или повторяющийся ID источника.';
                continue;
            }
            $ids[$id] = true;
            foreach (['title', 'url', 'checked_at'] as $required) {
                if (!is_string($source[$required] ?? null) || $source[$required] === '') {
                    $errors[] = $id . ': отсутствует ' . $required . '.';
                }
            }
            if (!editorialSourceUrlIsValid((string) ($source['url'] ?? ''))) {
                $errors[] = $id . ': некорректный URL.';
            }
            foreach ($source['claims'] ?? [] as $claim) {
                foreach (['id', 'point', 'scope', 'excerpt', 'evidence_file'] as $required) {
                    if (!is_string($claim[$required] ?? null) || $claim[$required] === '') {
                        $errors[] = $id . ': неполная карточка утверждения.';
                    }
                }
                $evidencePath = $libraryDir . '/' . ($claim['evidence_file'] ?? '');
                if (!is_file($evidencePath)) {
                    $errors[] = $id . ': отсутствует выдержка ' . ($claim['evidence_file'] ?? '') . '.';
                }
            }
            $rawFile = $source['raw_file'] ?? '';
            $rawHash = $source['raw_sha256'] ?? '';
            if (is_string($rawFile) && $rawFile !== '') {
                $rawPath = $libraryDir . '/' . $rawFile;
                if (!is_file($rawPath)) {
                    $warnings[] = $id . ': локальный первичный файл ещё не закэширован.';
                } elseif ($rawHash !== '' && !hash_equals($rawHash, hash_file('sha256', $rawPath))) {
                    $errors[] = $id . ': SHA256 локального файла не совпадает с каталогом.';
                }
            }
        }
        foreach ($warnings as $warning) { echo "ПРЕДУПРЕЖДЕНИЕ: {$warning}\n"; }
        foreach ($errors as $error) { fwrite(STDERR, "ОШИБКА: {$error}\n"); }
        printf("Карточек: %d. Ошибок: %d.\n", count($catalog['sources']), count($errors));
        exit($errors === [] ? 0 : 1);
    }

    if (isset($options['refresh'])) {
        $source = editorialSourceFind($catalog, (string) $options['refresh']);
        $rawFile = $source['raw_file'] ?? '';
        if (!is_string($rawFile) || $rawFile === '') {
            throw new RuntimeException('Для источника не задан путь локального кэша.');
        }
        if (!preg_match('~^raw/[a-z0-9][a-z0-9-]*\.pdf$~iD', $rawFile)) {
            throw new RuntimeException('Недопустимый путь локального кэша.');
        }
        if (!str_starts_with((string) $source['url'], 'https://')) {
            throw new RuntimeException('Кэшировать можно только HTTPS-источник.');
        }
        $target = $libraryDir . '/' . $rawFile;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0700, true) && !is_dir(dirname($target))) {
            throw new RuntimeException('Не удалось создать каталог кэша.');
        }
        $context = stream_context_create(['http' => ['timeout' => 45], 'https' => ['timeout' => 45]]);
        $contents = file_get_contents($source['url'], false, $context);
        if ($contents === false || $contents === '') {
            throw new RuntimeException('Не удалось скачать первичный документ.');
        }
        if (!str_starts_with($contents, '%PDF-')) {
            throw new RuntimeException('Источник вернул не PDF-документ; локальный кэш не обновлён.');
        }
        $temporary = $target . '.tmp';
        if (file_put_contents($temporary, $contents, LOCK_EX) === false || !rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось записать локальный кэш.');
        }
        printf("Кэш обновлён: %s\nSHA256: %s\n", $rawFile, hash_file('sha256', $target));
        exit(0);
    }

    editorialSourceUsage();
    exit(1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Ошибка: ' . $e->getMessage() . "\n");
    exit(1);
}

<?php
declare(strict_types=1);

function publicationSeoRegistry(): array
{
    static $data;
    return $data ??= json_decode(file_get_contents(__DIR__ . '/seo-publication-registry.json'), true, 512, JSON_THROW_ON_ERROR);
}

function publicationSeoEntry(int $id): array
{
    static $byId;
    if ($byId === null) {
        $byId = [];
        foreach (publicationSeoRegistry()['pages'] as $row) {
            if (isset($row['id'])) {
                $byId[$row['id']] = $row;
            }
        }
    }
    return $byId[$id] ?? [];
}

function publicationSeoExcludedIds(): array
{
    static $ids;
    return $ids ??= array_values(array_map(
        static fn(array $row): int => $row['id'],
        array_filter(publicationSeoRegistry()['pages'], static fn(array $row): bool =>
            in_array($row['action'] ?? '', ['CANONICAL_ALIAS', 'HIDE_PENDING_ORIGINAL'], true))
    ));
}

function publicationSeoPath(string $path): string
{
    return publicationSeoRegistry()['pages'][$path]['redirect'] ?? $path;
}

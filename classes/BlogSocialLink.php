<?php
declare(strict_types=1);

/** Короткие ссылки только на опубликованные статьи нашего блога. */
final class BlogSocialLink
{
    private const SOURCES = ['tg' => 'telegram', 'vk' => 'vk'];
    private const MAX_HOPS = 20;

    public static function url(string $source, int $publicationId, string $baseUrl = 'https://fgos.pro'): string
    {
        if (!isset(self::SOURCES[$source]) || $publicationId < 1) {
            throw new InvalidArgumentException('Некорректный источник или ID статьи');
        }
        return self::origin($baseUrl) . '/s/' . $source . '/' . $publicationId;
    }

    /** @return array{source:string,publication_id:int}|null */
    public static function parsePath(string $requestUri): ?array
    {
        // REQUEST_URI сохраняет исходный путь после внутреннего rewrite; query не доверяем.
        $path = explode('?', $requestUri, 2)[0];
        if (!preg_match('~\A/s/(tg|vk)/([1-9][0-9]*)/?\z~D', $path, $matches)) {
            return null;
        }
        $id = filter_var($matches[2], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false ? null : ['source' => $matches[1], 'publication_id' => $id];
    }

    /** PDO MySQL должен читать TIMESTAMP в UTC, как публикаторы блога. */
    public static function resolve(PDO $db, string $source, int $publicationId, string $baseUrl = 'https://fgos.pro'): ?string
    {
        if (!isset(self::SOURCES[$source]) || $publicationId < 1) {
            return null;
        }
        $origin = self::origin($baseUrl);
        // published_at записан московским временем через UTC-соединение.
        $now = (new DateTimeImmutable('now', new DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
        $select = "SELECT id,slug,redirect_to_slug FROM publications WHERE source='blog' AND status='published' AND published_at <= ? AND ";
        $byId = $db->prepare($select . 'id = ?');
        $bySlug = $db->prepare($select . 'slug = ?');
        $byId->execute([$now, $publicationId]);
        $article = $byId->fetch(PDO::FETCH_ASSOC);
        $seen = [];
        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (!$article || isset($seen[(string)$article['id']])) {
                return null;
            }
            $seen[(string)$article['id']] = true;
            $slug = (string)$article['slug'];
            if (!preg_match('/\A[a-z0-9-]+\z/D', $slug)) {
                return null;
            }
            $redirect = trim((string)($article['redirect_to_slug'] ?? ''));
            if ($redirect === '') {
                return $origin . '/blog/' . rawurlencode($slug) . '/?'
                    . http_build_query(['utm_source' => self::SOURCES[$source], 'utm_campaign' => 'blog'], '', '&', PHP_QUERY_RFC3986);
            }
            if (!preg_match('/\A[a-z0-9-]+\z/D', $redirect)) {
                return null;
            }
            $bySlug->execute([$now, $redirect]);
            $article = $bySlug->fetch(PDO::FETCH_ASSOC);
        }
        return null;
    }

    private static function origin(string $baseUrl): string
    {
        $parts = parse_url($baseUrl);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https'
            || !in_array(strtolower($parts['host'] ?? ''), ['fgos.pro', 'www.fgos.pro'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['port']) || isset($parts['query']) || isset($parts['fragment'])
            || !in_array($parts['path'] ?? '', ['', '/'], true)) {
            throw new InvalidArgumentException('Короткая ссылка должна вести на https://fgos.pro');
        }
        return 'https://fgos.pro';
    }
}

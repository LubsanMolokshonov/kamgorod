<?php
declare(strict_types=1);

/** Read-only RSS for Postmypost draft import. Does not publish or acknowledge delivery. */
final class FgosBlogDraftRss
{
    public static function plain(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? '';
        $html = preg_replace('~</(?:p|h[1-6]|li|div)>|<br\s*/?>~i', ' ', $html) ?? '';
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    public static function cover(string $value, string $root): ?array
    {
        $parts = parse_url($value);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) ||
            (isset($parts['host']) && ($parts['host'] !== 'fgos.pro' || ($parts['scheme'] ?? '') !== 'https'))) { return null; }
        $path = '/' . ltrim($parts['path'] ?? '', '/');
        $decoded = rawurldecode($path);
        if (str_contains($decoded, "\0") || str_contains($decoded, '\\') || str_starts_with($value, '//')) { return null; }
        $resolved = realpath($root . $decoded);
        $prefix = rtrim((string)realpath($root), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!$resolved || !str_starts_with($resolved, $prefix) || !is_file($resolved) || !is_readable($resolved)) { return null; }
        $info = @getimagesize($resolved);
        if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) { return null; }
        return ['url' => 'https://fgos.pro' . $path, 'mime' => $info['mime'], 'length' => filesize($resolved)];
    }

    public static function render(PDO $db, ?Closure $image = null): string
    {
        $query = $db->prepare("SELECT p.id,p.title,p.slug,p.annotation,p.content,p.cover_image_url,p.published_at
            FROM publications p WHERE p.source='blog' AND p.status='published'
            AND (p.redirect_to_slug IS NULL OR p.redirect_to_slug='')
            AND NOT EXISTS (SELECT 1 FROM vk_blog_deliveries d WHERE d.publication_id=p.id AND d.group_id=?
                AND (d.state<>'pending' OR d.post_id IS NOT NULL))
            ORDER BY p.published_at,p.id");
        $query->execute([200065283]);
        $image ??= static fn(string $url) => self::cover($url, __DIR__);
        $xml = new XMLWriter(); $xml->openMemory(); $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('rss'); $xml->writeAttribute('version', '2.0'); $xml->startElement('channel');
        $xml->writeElement('title', 'ФГОС — статьи блога для черновиков VK');
        $xml->writeElement('link', 'https://fgos.pro/blog/');
        $xml->writeElement('description', 'Опубликованные статьи с исходной обложкой. Для импорта в черновики Postmypost.');
        $xml->writeElement('language', 'ru');
        while ($article = $query->fetch(PDO::FETCH_ASSOC)) {
            if (empty($article['cover_image_url']) || empty($article['slug'])) { continue; }
            $cover = $image((string)$article['cover_image_url']);
            if (!$cover) { continue; }
            $annotation = self::plain((string)($article['annotation'] ?? ''));
            if ($annotation === '') { $annotation = mb_substr(self::plain((string)$article['content']), 0, 300); }
            $xml->startElement('item');
            $xml->writeElement('title', self::plain((string)$article['title']));
            $xml->writeElement('link', 'https://fgos.pro/blog/' . rawurlencode($article['slug']) . '/?utm_source=vk&utm_campaign=blog');
            $xml->startElement('guid'); $xml->writeAttribute('isPermaLink', 'false');
            $xml->text('fgos.pro:blog:publication:' . (int)$article['id']); $xml->endElement();
            $xml->writeElement('description', $annotation);
            if (!empty($article['published_at'])) {
                try { $date = new DateTimeImmutable($article['published_at'], new DateTimeZone('UTC'));
                    $xml->writeElement('pubDate', $date->format(DATE_RSS));
                } catch (Exception) { /* Invalid historical date is not a new publication. */ }
            }
            $xml->startElement('enclosure');
            $xml->writeAttribute('url', $cover['url']); $xml->writeAttribute('type', $cover['mime']);
            $xml->writeAttribute('length', (string)$cover['length']); $xml->endElement();
            $xml->endElement();
        }
        $xml->endElement(); $xml->endElement(); $xml->endDocument();
        return $xml->outputMemory();
    }
}

// The same renderer is included by the CLI regression test without executing HTTP logic.
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) { return; }
header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405); header('Allow: GET, HEAD'); exit;
}
try {
    require_once __DIR__ . '/config/config.php';
    $rssDb = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5]);
    $output = FgosBlogDraftRss::render($rssDb);
    header('Content-Type: application/rss+xml; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') { echo $output; }
} catch (Throwable) {
    http_response_code(503); header('Retry-After: 3600'); header('Content-Type: text/plain; charset=UTF-8');
    echo 'RSS temporarily unavailable';
}
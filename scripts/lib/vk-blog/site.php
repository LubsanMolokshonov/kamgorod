<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }

interface VkBlogSiteGateway
{
    /** Проверяет страницу и возвращает байты именно её обложки. */
    public function prepare(array $article): array;
}

final class VkBlogSite implements VkBlogSiteGateway
{
    public function __construct(private string $baseUrl) {}

    public static function plain(string $html): string
    {
        $html = preg_replace('~<(script|style)\b[^>]*>.*?</\1>~is', '', $html) ?? '';
        $html = preg_replace('~</(?:p|h[1-6]|li|div)>|<br\s*/?>~i', ' ', $html) ?? '';
        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')) ?? '');
    }

    public function url(array $article): string
    {
        return rtrim($this->baseUrl, '/') . '/blog/' . rawurlencode($article['slug']) . '/';
    }

    public static function payload(array $article, string $url): array
    {
        $title = self::plain((string)$article['title']);
        $annotation = self::plain((string)($article['annotation'] ?? ''));
        if ($annotation === '') { $annotation = mb_substr(self::plain((string)$article['content']), 0, 300); }
        $message = $title . "\n\n" . $annotation . "\n\nЧитать на сайте: " . $url;
        if (mb_strlen($message) > 15000) { throw new RuntimeException('Анонс превышает допустимый размер поста'); }
        return ['url' => $url, 'cover_url' => $article['cover_image_url'], 'message' => $message];
    }

    private function absolute(string $url): string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return rtrim($this->baseUrl, '/') . $url;
        }
        if (!str_contains($url, '://') && !str_starts_with($url, '//')) {
            return rtrim($this->baseUrl, '/') . '/' . $url;
        }
        return $url;
    }

    private function fetch(string $url, int $limit): string
    {
        $base = parse_url($this->baseUrl);
        $target = parse_url($url);
        if (($target['scheme'] ?? '') !== 'https' || ($target['host'] ?? '') !== ($base['host'] ?? '') ||
            ($target['port'] ?? 443) !== ($base['port'] ?? 443) || isset($target['user']) || isset($target['pass'])) {
            throw new RuntimeException('Страница и обложка должны находиться на HTTPS-домене сайта');
        }
        $bytes = '';
        $ch = curl_init($url);
        // В Windows используем доверенные корни ОС; проверка TLS остаётся включённой.
        if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA')) {
            curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
        }
        curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_USERAGENT => 'fgos-vk-blog/1.0',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$bytes, $limit): int {
                if (strlen($bytes) + strlen($chunk) > $limit) { return 0; }
                $bytes .= $chunk;
                return strlen($chunk);
            }]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($ok === false || $status !== 200 || $bytes === '') { throw new RuntimeException('Страница или обложка недоступна (HTTP ' . $status . ')'); }
        return $bytes;
    }

    public static function image(string $bytes): array
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > 40000000) {
            throw new RuntimeException('Недопустимый файл обложки');
        }
        $mime = $info['mime'];
        if (in_array($mime, ['image/jpeg', 'image/png'], true)) { return ['bytes' => $bytes, 'mime' => $mime]; }
        if ($mime !== 'image/webp' || !function_exists('imagecreatefromstring')) {
            throw new RuntimeException('Формат обложки не поддерживается; замена запрещена');
        }
        $source = @imagecreatefromstring($bytes);
        if (!$source) { throw new RuntimeException('Не удалось прочитать WebP'); }
        // PNG сохраняет размеры, прозрачность и пиксели; генерации и кадрирования нет.
        imagesavealpha($source, true);
        ob_start();
        imagepng($source);
        $png = ob_get_clean();
        imagedestroy($source);
        if (!is_string($png) || $png === '') { throw new RuntimeException('Ошибка преобразования WebP в PNG'); }
        return ['bytes' => $png, 'mime' => 'image/png'];
    }

    public function prepare(array $article): array
    {
        if (empty($article['cover_image_url'])) { throw new RuntimeException('У статьи нет обложки'); }
        $url = $this->url($article);
        $html = $this->fetch($url, 5000000);
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new DOMXPath($dom);
        $canonical = $xpath->query('//link[@rel="canonical"]/@href')->item(0)?->nodeValue;
        $cover = $xpath->query('//img[contains(concat(" ",normalize-space(@class)," ")," pub-cover ")]/@src')->item(0)?->nodeValue;
        if ($canonical !== $url || !$cover || $this->absolute($cover) !== $this->absolute($article['cover_image_url'])) {
            throw new RuntimeException('Страница не соответствует статье или обложка на сайте изменилась');
        }
        $source = $this->fetch($this->absolute($cover), 20000000);
        return ['payload' => self::payload($article, $url), 'sha256' => hash('sha256', $source)] + self::image($source);
    }
}

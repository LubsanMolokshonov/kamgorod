<?php
/**
 * URL Helper Functions
 * Smart URL generation for competitions based on audience context
 */

/**
 * Generate competition URL based on audience types and context
 *
 * @param string $slug Competition slug
 * @param array $audienceTypes Array of audience type objects with 'slug' key
 * @param string|null $contextAudience Current audience filter slug (e.g., 'dou', 'nachalnaya-shkola')
 * @return string Clean URL for the competition
 */
function getCompetitionUrl($slug, $audienceTypes = [], $contextAudience = null) {
    return buildProductUrl('konkursy', (string)$slug) ?? '';
}

/** URL одного продукта. Принимает исходный или однократно закодированный slug. */
function buildProductUrl(string $section, string $slug, $id = null): ?string {
    $slug = rawurldecode($slug);
    if ($slug === '' || trim($slug) !== $slug || !preg_match('/^[\p{L}\p{N}_ -]+$/u', $slug)
        || preg_match('/(?:encodeURIComponent|_compEsc|javascript)/i', $slug)) {
        error_log('Некорректный slug: ' . $section . ', ID=' . (int)$id);
        return null;
    }
    return '/' . $section . '/' . rawurlencode($slug) . '/';
}

function getCourseUrl($slug, $id = null): ?string {
    return buildProductUrl('kursy', (string)$slug, $id);
}

/** Нормализация только внутренних ссылок на HTML, без изменения query/hash. */
function normalizeInternalUrl(string $url): string {
    if ($url === '' || $url[0] === '#' || preg_match('/[\x00-\x20<>"\'\\\\]/', $url)
        || preg_match('/%2[27]|encodeURIComponent|_compEsc/i', $url)) return $url;
    $parts = parse_url($url);
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return $url;
    $host = strtolower($parts['host'] ?? '');
    if ($host !== '' && !in_array($host, ['fgos.pro', 'www.fgos.pro'], true)) return $url;
    if (isset($parts['scheme']) && !in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return $url;
    if ($host === '' && !str_starts_with($url, '/')) return $url;
    $path = $parts['path'] ?? '/';
    if (preg_match('~^/(?:api|ajax|ai-chat|ai-consultant)(?:/|$)~', $path)
        || preg_match('~\.[^/]+$~', $path)) return $url;
    $path = rtrim($path, '/') . '/';
    return ($host !== '' ? 'https://fgos.pro' : '') . $path
        . (isset($parts['query']) ? '?' . $parts['query'] : '')
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

/** Обработка фрагмента при отображении: БД и экспортные документы не меняются. */
function normalizeContentHtml(string $html, ?string $pageTitle = null): string {
    if ($html === '') return $html;
    $dom = new DOMDocument('1.0', 'UTF-8');
    $previous = libxml_use_internal_errors(true);
    $loaded = $dom->loadHTML('<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="seo-fragment">' . $html . '</div></body></html>', LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if (!$loaded) return $html;
    $root = $dom->getElementById('seo-fragment');
    if (!$root) return $html;
    foreach ($root->getElementsByTagName('a') as $a) {
        if ($a->hasAttribute('href')) $a->setAttribute('href', normalizeInternalUrl($a->getAttribute('href')));
    }
    if ($pageTitle !== null) {
        $plain = static fn($s) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($s), ENT_QUOTES, 'UTF-8'))));
        foreach (iterator_to_array($root->getElementsByTagName('h1')) as $heading) {
            $replacement = $dom->createElement($plain($heading->textContent) === $plain($pageTitle) ? 'p' : 'h2');
            foreach ($heading->attributes as $attribute) $replacement->setAttribute($attribute->name, $attribute->value);
            $replacement->setAttribute('class', trim($replacement->getAttribute('class') . ' material-content-heading'));
            while ($heading->firstChild) $replacement->appendChild($heading->firstChild);
            $heading->parentNode->replaceChild($replacement, $heading);
        }
    }
    $result = '';
    foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);
    return $result;
}

/**
 * Get current audience context from request
 *
 * @return string|null Audience slug or null if not in audience context
 */
function getCurrentAudienceContext() {
    // Check if we're filtering by audience
    $audienceFilter = $_GET['audience'] ?? null;
    if ($audienceFilter) {
        return $audienceFilter;
    }

    return null;
}

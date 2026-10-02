<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }

/** Независимые SEO-поля: исходные названия и данные документов не изменяются. */
function seoPageData(PDO $pdo, ?string $path = null): array {
    static $cache = [];
    $path = $path ?? (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
    $key = spl_object_id($pdo) . ':' . $path;
    if (!array_key_exists($key, $cache)) {
        try {
            $q = $pdo->prepare('SELECT * FROM seo_page_overrides WHERE path = ?');
            $q->execute([$path]);
            $cache[$key] = $q->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            // Совместимость до выкладки миграции; другие ошибки не скрываем.
            if ($e->getCode() !== '42S02') throw $e;
            $cache[$key] = [];
        }
    }
    return $cache[$key];
}

function seoHeading(string $fallback): string {
    global $seoPage;
    return (string)($seoPage['seo_h1'] ?? '') !== '' ? $seoPage['seo_h1'] : $fallback;
}

function seoEditorial(array $page): array {
    return json_decode($page['editorial_json'] ?? '{}', true) ?: [];
}

function seoEditorialSchema(array $schema, array $editorial): array {
    foreach (['author', 'editor'] as $role) {
        if ($role === 'author' && str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/publikaciya/')) continue;
        if (!empty($editorial[$role . '_name']) && !empty($editorial[$role . '_url'])) {
            $schema[$role] = ['@type' => ($editorial[$role . '_type'] ?? '') === 'Organization' ? 'Organization' : 'Person',
                'name' => $editorial[$role . '_name'], 'url' => rtrim(SITE_URL, '/') . $editorial[$role . '_url']];
        }
    }
    if (!empty($editorial['content_updated_at'])) $schema['dateModified'] = $editorial['content_updated_at'];
    if (!empty($editorial['sources'])) $schema['citation'] = array_values($editorial['sources']);
    return $schema;
}

function renderSeoEditorial(array $page): string {
    $e = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $d = seoEditorial($page); $html = '';
    if (str_starts_with(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?? '', '/publikaciya/')) foreach(['author_name','author_url','author_role'] as $field) unset($d[$field]);
    foreach (['author' => 'Автор / составитель', 'editor' => 'Редактор'] as $key => $label) {
        if (empty($d[$key . '_name']) || empty($d[$key . '_url'])) continue;
        $html .= '<p>' . $label . ': <a href="' . $e($d[$key . '_url']) . '">' . $e($d[$key . '_name']) . '</a>';
        if (!empty($d[$key . '_role'])) $html .= ' — ' . $e($d[$key . '_role']);
        $html .= '</p>';
    }
    if (!empty($d['content_updated_at'])) $html .= '<p>Актуализировано: <time datetime="' . $e($d['content_updated_at']) . '">' . $e($d['content_updated_at']) . '</time></p>';
    if (!empty($d['reviewed_at']) && !empty($d['editor_name'])) $html .= '<p>Проверено редактором: ' . $e($d['reviewed_at']) . '</p>';
    if (!empty($d['sources'])) {
        $html .= '<p>Первоисточники:</p><ul>';
        foreach ($d['sources'] as $source) $html .= '<li><a href="' . $e($source) . '" rel="noopener">' . $e($source) . '</a></li>';
        $html .= '</ul>';
    }
    if (!empty($page['intro_text'])) $html = '<p>' . nl2br($e($page['intro_text'])) . '</p>' . $html;
    return $html !== '' ? '<section class="seo-editorial" aria-label="Сведения о материале">' . $html . '</section>' : '';
}

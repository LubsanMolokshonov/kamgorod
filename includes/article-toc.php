<?php
/**
 * Автоматическое оглавление статьи.
 *
 * buildArticleToc($html) парсит подзаголовки <h2>/<h3> в HTML-теле публикации,
 * проставляет им якорные id и возвращает изменённый HTML вместе со списком
 * пунктов оглавления. Если заголовков меньше 3 — оглавление не формируется.
 */

if (!function_exists('articleTocSlug')) {
    /**
     * Транслитерированный slug из текста заголовка для якоря.
     */
    function articleTocSlug($text) {
        $map = [
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','е'=>'e','ё'=>'e','ж'=>'zh',
            'з'=>'z','и'=>'i','й'=>'y','к'=>'k','л'=>'l','м'=>'m','н'=>'n','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','у'=>'u','ф'=>'f','х'=>'h','ц'=>'ts',
            'ч'=>'ch','ш'=>'sh','щ'=>'sch','ъ'=>'','ы'=>'y','ь'=>'','э'=>'e','ю'=>'yu','я'=>'ya',
        ];
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, $map);
        $text = preg_replace('/[^a-z0-9]+/', '-', $text);
        $text = trim($text, '-');
        return $text !== '' ? $text : 'razdel';
    }
}

if (!function_exists('buildArticleToc')) {
    /**
     * @param string $html HTML-тело статьи
     * @return array ['html' => string, 'toc' => array<['level'=>2|3,'id'=>string,'text'=>string]>]
     */
    function buildArticleToc($html) {
        if (trim($html) === '' || stripos($html, '<h2') === false && stripos($html, '<h3') === false) {
            return ['html' => $html, 'toc' => []];
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML(
            '<?xml encoding="utf-8"?><div id="__toc_root__">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        if (!$loaded) {
            return ['html' => $html, 'toc' => []];
        }

        $xpath = new DOMXPath($dom);
        $rootNodes = $xpath->query('//div[@id="__toc_root__"]');
        if (!$rootNodes || $rootNodes->length === 0) {
            return ['html' => $html, 'toc' => []];
        }
        $root = $rootNodes->item(0);

        $headings = $xpath->query('.//h2 | .//h3', $root);
        if (!$headings || $headings->length < 3) {
            return ['html' => $html, 'toc' => []];
        }

        $toc = [];
        $usedIds = [];
        $index = 0;
        foreach ($headings as $node) {
            $index++;
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent));
            if ($text === '') {
                continue;
            }

            $existingId = $node->getAttribute('id');
            if ($existingId !== '' && !in_array($existingId, $usedIds, true)) {
                $id = $existingId;
            } else {
                $base = articleTocSlug($text);
                $id = $base;
                $n = 1;
                while (in_array($id, $usedIds, true)) {
                    $id = $base . '-' . (++$n);
                }
                $node->setAttribute('id', $id);
            }
            $usedIds[] = $id;

            $toc[] = [
                'level' => $node->nodeName === 'h3' ? 3 : 2,
                'id' => $id,
                'text' => $text,
            ];
        }

        if (count($toc) < 3) {
            return ['html' => $html, 'toc' => []];
        }

        // innerHTML корневого div — без обёртки и без xml-инструкции.
        $newHtml = '';
        foreach ($root->childNodes as $child) {
            $newHtml .= $dom->saveHTML($child);
        }

        return ['html' => $newHtml, 'toc' => $toc];
    }
}

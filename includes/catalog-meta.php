<?php
/**
 * Хелперы для динамической сборки H1, <title> и meta description
 * на страницах-каталогах (конкурсы, олимпиады, вебинары, журнал, курсы).
 *
 * Цель: одинаковые статичные H1/title на десятках уникальных URL → дубли в индексе.
 * Решение: собирать заголовок из выбранных фильтров.
 */

if (!function_exists('buildAudiencePhrase')) {
    /**
     * Возвращает фразу аудитории в родительном падеже.
     *
     * Приоритет:
     *  1. target_participants_genitive из БД (audience_types) — точная фраза от редактора
     *  2. AUDIENCE_CATEGORY_GENITIVE_MAP по слагу категории
     *  3. имя категории/типа в нижнем регистре
     *  4. fallback ("педагогов")
     *
     * Если выбрана специализация — добавляем " — <название>".
     */
    function buildAudiencePhrase(?array $cat, ?array $type, ?array $spec, string $fallback = 'педагогов'): string
    {
        $phrase = $fallback;
        $map = defined('AUDIENCE_CATEGORY_GENITIVE_MAP') ? AUDIENCE_CATEGORY_GENITIVE_MAP : [];

        if ($type && !empty($type['target_participants_genitive'])) {
            $phrase = $type['target_participants_genitive'];
        } elseif ($cat && isset($map[$cat['slug']])) {
            $phrase = $map[$cat['slug']];
        } elseif ($cat && !empty($cat['name'])) {
            $phrase = mb_strtolower($cat['name']);
        } elseif ($type && !empty($type['name'])) {
            $phrase = mb_strtolower($type['name']);
        }

        if ($spec && !empty($spec['name'])) {
            $phrase .= ' — ' . $spec['name'];
        }

        return $phrase;
    }

    /**
     * Собирает H1, title, description из частей.
     *
     * @param array $parts {
     *   @var string $base               напр. "Конкурсы", "Методические конкурсы", "Предстоящие вебинары"
     *   @var string $audiencePhrase     результат buildAudiencePhrase()
     *   @var bool   $hasFilter          задан ли фильтр (для решения, ставить ли accent на аудиторию)
     *   @var string $titleSuffix        добавляется к title, напр. " 2025-2026"
     *   @var string $descriptionTpl     шаблон описания с плейсхолдером {h1}
     *   @var string $h1FallbackAccent   HTML accent-хвост для fallback-варианта (без фильтра)
     *   @var string $h1FallbackPrefix   текст до accent в fallback, напр. "Конкурсы для педагогов с "
     * }
     * @return array{h1: string, h1_html: string, title: string, description: string}
     */
    function buildCatalogMeta(array $parts): array
    {
        $base = $parts['base'] ?? '';
        $audiencePhrase = $parts['audiencePhrase'] ?? '';
        $hasFilter = $parts['hasFilter'] ?? false;
        $titleSuffix = $parts['titleSuffix'] ?? '';
        $descriptionTpl = $parts['descriptionTpl'] ?? '{h1}.';
        $connector = $parts['connector'] ?? ' для ';

        $h1Plain = $base . $connector . $audiencePhrase;

        // HTML-версия H1: если фильтр задан — оборачиваем аудиторию в span.accent.
        // Иначе используем fallback-вариант с прежним accent-хвостом.
        if ($hasFilter) {
            $h1Html = htmlspecialchars($base . $connector, ENT_QUOTES, 'UTF-8')
                    . '<span class="accent">' . htmlspecialchars($audiencePhrase, ENT_QUOTES, 'UTF-8') . '</span>';
        } else {
            $fallbackPrefix = $parts['h1FallbackPrefix'] ?? null;
            $fallbackAccent = $parts['h1FallbackAccent'] ?? null;
            if ($fallbackPrefix !== null && $fallbackAccent !== null) {
                // В HTML оставляем &nbsp; как есть (для красивых неразрывных пробелов).
                // В plain-варианте (для title) — декодируем сущности и схлопываем пробелы.
                $h1Html = $fallbackPrefix . '<span class="accent">' . $fallbackAccent . '</span>';
                $plainPrefix = html_entity_decode(strip_tags($fallbackPrefix), ENT_QUOTES, 'UTF-8');
                $plainAccent = html_entity_decode(strip_tags($fallbackAccent), ENT_QUOTES, 'UTF-8');
                $h1Plain = preg_replace('/\s+/u', ' ', trim($plainPrefix . $plainAccent));
            } else {
                $h1Html = htmlspecialchars($h1Plain, ENT_QUOTES, 'UTF-8');
            }
        }

        $title = $h1Plain . $titleSuffix;
        $description = str_replace('{h1}', $h1Plain, $descriptionTpl);

        return [
            'h1' => $h1Plain,
            'h1_html' => $h1Html,
            'title' => $title,
            'description' => $description,
        ];
    }
}

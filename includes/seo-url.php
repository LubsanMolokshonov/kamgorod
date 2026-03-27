<?php
/**
 * SEO URL Helper
 * Построение чистых URL и 301-редиректы со старых query-param URL
 */

/**
 * Построить SEO-friendly URL
 *
 * @param string $section Секция: 'konkursy', 'olimpiady', 'vebinary', 'zhurnal'
 * @param array $options Параметры фильтрации:
 *   'category' => internal key (methodology, extracurricular, ...)
 *   'status'   => internal key (upcoming, recordings, videolecture)
 *   'ac'       => audience category slug
 *   'at'       => audience type slug
 *   'as'       => audience specialization slug
 *   + любые другие параметры уйдут в query string
 * @return string Чистый URL
 */
function buildSeoUrl($section, $options = []) {
    $path = '/' . $section;

    // Подкатегория секции → сегмент пути
    if ($section === 'konkursy' && !empty($options['category']) && $options['category'] !== 'all') {
        $map = defined('COMPETITION_CATEGORY_URL_MAP') ? COMPETITION_CATEGORY_URL_MAP : [];
        if (isset($map[$options['category']])) {
            $path .= '/' . $map[$options['category']];
        }
    }
    if ($section === 'vebinary' && !empty($options['status'])) {
        $map = defined('WEBINAR_STATUS_URL_MAP') ? WEBINAR_STATUS_URL_MAP : [];
        if (isset($map[$options['status']])) {
            $path .= '/' . $map[$options['status']];
        }
    }
    if ($section === 'kursy' && !empty($options['program_type']) && $options['program_type'] !== 'all') {
        $map = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];
        if (isset($map[$options['program_type']])) {
            $path .= '/' . $map[$options['program_type']];
        }
    }

    // Аудитория → сегменты пути
    if (!empty($options['ac'])) {
        $path .= '/' . rawurlencode($options['ac']);
        if (!empty($options['at'])) {
            $path .= '/' . rawurlencode($options['at']);
            if (!empty($options['as'])) {
                $path .= '/' . rawurlencode($options['as']);
            }
        }
    }

    $path .= '/';

    // Оставшиеся параметры → query string
    $queryKeys = array_diff_key($options, array_flip(['category', 'status', 'program_type', 'ac', 'at', 'as']));
    $queryKeys = array_filter($queryKeys, function($v) { return $v !== null && $v !== ''; });
    if (!empty($queryKeys)) {
        $path .= '?' . http_build_query($queryKeys);
    }

    return $path;
}

/**
 * Получить path-prefix для подкатегории секции (для audience-filter)
 *
 * @param string $section
 * @param array $options Только 'category' или 'status'
 * @return string Сегмент пути (например 'metodika' или 'predstoyashchie') или ''
 */
function getSectionPathPrefix($section, $options = []) {
    if ($section === 'konkursy' && !empty($options['category']) && $options['category'] !== 'all') {
        $map = defined('COMPETITION_CATEGORY_URL_MAP') ? COMPETITION_CATEGORY_URL_MAP : [];
        return $map[$options['category']] ?? '';
    }
    if ($section === 'vebinary' && !empty($options['status'])) {
        $map = defined('WEBINAR_STATUS_URL_MAP') ? WEBINAR_STATUS_URL_MAP : [];
        return $map[$options['status']] ?? '';
    }
    if ($section === 'kursy' && !empty($options['program_type']) && $options['program_type'] !== 'all') {
        $map = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];
        return $map[$options['program_type']] ?? '';
    }
    return '';
}

/**
 * 301-редирект со старых query-param URL на чистые
 * Вызывать до любого вывода (header)
 *
 * @param string $section Секция
 * @param array $currentParams Текущие распарсенные параметры
 */
function redirectToSeoUrl($section, $currentParams = []) {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '';
    $queryPos = strpos($requestUri, '?');

    // Если нет query string — ничего не редиректим
    if ($queryPos === false) return;

    $queryString = substr($requestUri, $queryPos + 1);

    // Проверяем, есть ли в query string параметры, которые должны быть в пути
    $seoParams = ['ac', 'at', 'as'];
    if ($section === 'konkursy') $seoParams[] = 'category';
    if ($section === 'vebinary') $seoParams[] = 'status';
    if ($section === 'kursy') $seoParams[] = 'program_type';

    parse_str($queryString, $queryParsed);

    $hasSeoParamInQuery = false;
    foreach ($seoParams as $param) {
        if (isset($queryParsed[$param]) && $queryParsed[$param] !== '') {
            $hasSeoParamInQuery = true;
            break;
        }
    }

    if (!$hasSeoParamInQuery) return;

    // Строим чистый URL из текущих параметров
    $seoOptions = $currentParams;

    // Добавляем прочие query-параметры (page, sort, q, tag, type и т.д.)
    $nonSeoKeys = array_diff_key($queryParsed, array_flip($seoParams));
    foreach ($nonSeoKeys as $k => $v) {
        if ($v !== '' && $v !== null) {
            $seoOptions[$k] = $v;
        }
    }

    $cleanUrl = buildSeoUrl($section, $seoOptions);

    // Не редиректим на себя
    $currentClean = strtok($requestUri, '?');
    $newClean = strtok($cleanUrl, '?');
    if (rtrim($currentClean, '/') === rtrim($newClean, '/')) {
        // Путь совпадает, но есть лишние SEO-параметры в query string
        // Перестроим query string без SEO-параметров
        $remainingQuery = array_diff_key($queryParsed, array_flip($seoParams));
        $remainingQuery = array_filter($remainingQuery, function($v) { return $v !== '' && $v !== null; });
        $newUrl = rtrim($currentClean, '/') . '/';
        if (!empty($remainingQuery)) {
            $newUrl .= '?' . http_build_query($remainingQuery);
        }
        if ($newUrl === $requestUri) return;
        header('Location: ' . $newUrl, true, 301);
        exit;
    }

    header('Location: ' . $cleanUrl, true, 301);
    exit;
}

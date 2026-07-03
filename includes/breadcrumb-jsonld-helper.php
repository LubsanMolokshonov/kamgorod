<?php
/**
 * JSON-LD BreadcrumbList без HTML-рендера.
 *
 * Для редизайн-страниц, которые рисуют свои крошки (.rd-crumbs) и которым
 * нужен только структурированный узел. HTML+JSON-LD вместе — includes/breadcrumbs.php.
 *
 * Использование:
 *   require_once __DIR__ . '/breadcrumb-jsonld-helper.php';
 *   $breadcrumbJsonLd = buildBreadcrumbJsonLd([
 *       ['label' => 'Главная', 'url' => '/'],
 *       ['label' => 'Материалы ФОП', 'url' => '/materialy/'],
 *       ['label' => 'Текущая страница'], // последний элемент — без URL
 *   ]);
 *   // $breadcrumbJsonLd автоматически выводится в includes/header.php
 */

if (!function_exists('buildBreadcrumbJsonLd')) {
    function buildBreadcrumbJsonLd(array $crumbs): array
    {
        $items = [];
        foreach ($crumbs as $i => $crumb) {
            $item = [
                '@type' => 'ListItem',
                'position' => $i + 1,
                'name' => $crumb['label'],
            ];
            if (!empty($crumb['url'])) {
                $item['item'] = SITE_URL . rtrim($crumb['url'], '/') . '/';
            }
            $items[] = $item;
        }
        return [
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => $items,
        ];
    }
}

<?php
/** Совместимый компонент; schema должна быть подготовлена до header.php. */
require_once __DIR__ . '/breadcrumb-jsonld-helper.php';
if (!empty($breadcrumbs) && is_array($breadcrumbs)) {
    $breadcrumbJsonLd = buildBreadcrumbJsonLd($breadcrumbs);
    echo renderBreadcrumbs($breadcrumbs, 'breadcrumbs');
}

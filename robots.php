<?php
/**
 * Dynamic robots.txt generator
 * Route: /robots.txt → robots.php (via .htaccess)
 */

require_once __DIR__ . '/config/config.php';

header('Content-Type: text/plain; charset=UTF-8');

$siteUrl = rtrim(SITE_URL, '/');
?>
User-agent: *
Allow: /

# Служебные страницы
Disallow: /kabinet/
Disallow: /korzina/
Disallow: /vhod/
Disallow: /vyhod/
Disallow: /olimpiada-test/
Disallow: /olimpiada-rezultat/
Disallow: /olimpiada-diplom/
Disallow: /opublikovat/
Disallow: /sertifikat-publikacii/
Disallow: /payment-success/

# Технические директории
Disallow: /pages/
Disallow: /ajax/
Disallow: /database/
Disallow: /classes/
Disallow: /config/
Disallow: /includes/

# Файлы
Disallow: /*.sql$
Disallow: /*.log$
Disallow: /*.env$

Sitemap: <?php echo $siteUrl; ?>/sitemap.xml

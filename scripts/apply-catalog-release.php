<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../classes/CatalogRelease.php';
$args = getopt('', ['file:', 'section:', 'apply', 'environment:', 'rollback:']);
$local = in_array(parse_url(SITE_URL, PHP_URL_HOST), ['localhost','127.0.0.1'], true);
if (isset($args['apply']) && ($args['environment'] ?? '') !== ($local ? 'local' : 'production')) throw new RuntimeException('Укажите фактическую среду --environment');
$release = new CatalogRelease($db);
if (isset($args['rollback'])) $result = ['reverted' => $release->rollback($args['rollback'], isset($args['apply']))];
else {
    if (empty($args['file']) || empty($args['section'])) throw new InvalidArgumentException('Нужны --file и --section=courses|competitions');
    $package = json_decode(file_get_contents($args['file']), true, 512, JSON_THROW_ON_ERROR);
    $result = $release->run($package, $args['section'], isset($args['apply']));
}
echo json_encode(['mode' => isset($args['apply']) ? 'apply' : 'dry-run', 'result' => $result], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

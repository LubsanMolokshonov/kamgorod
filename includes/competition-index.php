<?php
if (php_sapi_name() !== 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(403); die('CLI only'); }

function competitionRegistry(): array {
    static $registry;
    return $registry ??= json_decode(file_get_contents(__DIR__ . '/../editorial/catalog-20261021/registry.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Все исторические кандидаты категории должны быть разобраны, включая исключённые. */
function competitionClassificationReady(PDO $pdo, array $options): bool {
    $where = ['p.is_active=1', "(p.redirect_to_slug IS NULL OR p.redirect_to_slug='')"];
    $params = [];
    foreach (['ac'=>['competition_audience_categories','category_id','audience_categories'],
        'at'=>['competition_audience_types','audience_type_id','audience_types'],
        'as'=>['competition_specializations','specialization_id','audience_specializations']] as $key=>$tables) {
        if (empty($options[$key])) continue;
        [$junction,$foreign,$lookup] = $tables;
        $where[] = "EXISTS (SELECT 1 FROM $junction j JOIN $lookup a ON a.id=j.$foreign WHERE j.competition_id=p.id AND a.slug=?)";
        $params[] = $options[$key];
    }
    if (!empty($options['category']) && $options['category'] !== 'all') {
        $where[] = 'p.category=?'; $params[] = $options['category'];
    }
    $q = $pdo->prepare('SELECT COUNT(*) total, SUM(cf.competition_id IS NULL) pending FROM competitions p LEFT JOIN competition_catalog_facts cf ON cf.competition_id=p.id WHERE ' . implode(' AND ', $where));
    $q->execute($params); $row = $q->fetch(PDO::FETCH_ASSOC);
    return (int)$row['total'] > 0 && (int)$row['pending'] === 0;
}

<?php
/**
 * Бэкфилл классификации материалов: program_compliance + аудитория
 * (категория pedagogi + ступень) из ai_params_json.
 *
 * Идемпотентен: заполненные program_compliance и существующие аудиторные
 * связи не трогает (логика в Material::syncClassification).
 *
 * Запуск:
 *   php scripts/backfill-material-classification.php --dry-run   # только показать, что выведется
 *   php scripts/backfill-material-classification.php             # применить
 */

if (php_sapi_name() !== 'cli') {
    die("CLI only\n");
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialClassifier.php';

$dryRun = in_array('--dry-run', $argv, true);
$materialObj = new Material($db);
$dbw = new Database($db);

$rows = $dbw->query(
    "SELECT id, title, status, ai_params_json, program_compliance
     FROM materials
     WHERE ai_params_json IS NOT NULL AND ai_params_json != ''
     ORDER BY id"
);

$statPrograms = [];
$statStages = [];
$updated = 0;
$skipped = 0;

foreach ($rows as $row) {
    $params = json_decode((string)$row['ai_params_json'], true);
    if (!is_array($params)) {
        $skipped++;
        continue;
    }

    $programs = MaterialClassifier::derivePrograms($params);
    $stage = MaterialClassifier::deriveStage($params);
    $typeSlug = MaterialClassifier::audienceTypeSlug($params);

    foreach ($programs as $p) {
        $statPrograms[$p] = ($statPrograms[$p] ?? 0) + 1;
    }
    $statStages[$typeSlug ?? '(без ступени)'] = ($statStages[$typeSlug ?? '(без ступени)'] ?? 0) + 1;

    if ($dryRun) {
        printf(
            "#%-5d [%s] %-50s class=%-25s program=%-12s → %s | аудитория: pedagogi%s\n",
            $row['id'],
            $row['status'],
            mb_substr($row['title'], 0, 50),
            mb_substr((string)($params['class'] ?? '—'), 0, 25),
            mb_substr((string)($params['program'] ?? '—'), 0, 12),
            $programs ? implode(',', $programs) : '(пусто)',
            $typeSlug ? '/' . $typeSlug : ''
        );
        continue;
    }

    $res = $materialObj->syncClassification((int)$row['id']);
    if ($res['updated']) {
        $updated++;
    } else {
        $skipped++;
    }
}

echo "\n=== Распределение program_compliance ===\n";
arsort($statPrograms);
foreach ($statPrograms as $p => $c) {
    echo "  $p: $c\n";
}
echo "=== Распределение ступеней (audience_types) ===\n";
arsort($statStages);
foreach ($statStages as $s => $c) {
    echo "  $s: $c\n";
}
echo $dryRun
    ? "\nDRY-RUN: изменений не внесено. Всего материалов: " . count($rows) . "\n"
    : "\nОбновлено: $updated, пропущено (уже размечены/нет данных): $skipped\n";

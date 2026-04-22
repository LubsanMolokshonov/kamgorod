<?php
/**
 * Backfill: проставить bitrix_lead_id для ранее созданных заявок на консультацию,
 * у которых сделка в Bitrix24 уже была заведена, но в course_consultations не
 * записана (до внедрения миграции 084).
 *
 * Ищет сделки в Bitrix24 по TITLE вида "Консультация по курсу — {phone}"
 * в пределах CATEGORY_ID = BITRIX24_COURSE_PIPELINE_ID.
 *
 * Запуск:
 *   docker exec pedagogy_web php /var/www/html/scripts/backfill-course-consultations-bitrix.php [--dry-run] [--days=7]
 */

if (php_sapi_name() !== 'cli') {
    exit('CLI only');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';

$dryRun = in_array('--dry-run', $argv, true);
$days = 7;
foreach ($argv as $a) {
    if (preg_match('/^--days=(\d+)$/', $a, $m)) {
        $days = (int)$m[1];
    }
}

$dbObj = new Database($db);

$categoryId = defined('BITRIX24_COURSE_PIPELINE_ID') ? BITRIX24_COURSE_PIPELINE_ID : 108;
$stageNew   = defined('BITRIX24_COURSE_STAGE_NEW')    ? BITRIX24_COURSE_STAGE_NEW   : 'C108:NEW';
$webhook    = rtrim(defined('BITRIX24_WEBHOOK_URL') ? BITRIX24_WEBHOOK_URL : '', '/');

if ($webhook === '') {
    exit("BITRIX24_WEBHOOK_URL is not configured\n");
}

$rows = $dbObj->query(
    "SELECT id, phone, course_title, created_at, status, bitrix_lead_id
     FROM course_consultations
     WHERE bitrix_lead_id IS NULL
       AND created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
     ORDER BY created_at ASC",
    [$days]
);

echo "Found " . count($rows) . " consultations without bitrix_lead_id (last {$days} days)\n";
if ($dryRun) echo "DRY RUN — no DB writes\n";

$matched = 0;
$notFound = 0;

foreach ($rows as $r) {
    // Ищем сделку по TITLE. Шаблон "Консультация по курсу — {phone}" из createCourseConsultationDeal().
    $title = 'Консультация по курсу — ' . $r['phone'];
    $params = http_build_query([
        'filter' => [
            'CATEGORY_ID' => $categoryId,
            'TITLE'       => $title,
        ],
        'select' => ['ID', 'TITLE', 'STAGE_ID', 'DATE_CREATE'],
        'order'  => ['DATE_CREATE' => 'DESC'],
    ]);

    $resp = @file_get_contents($webhook . '/crm.deal.list.json?' . $params);
    if ($resp === false) {
        echo "  #{$r['id']} phone={$r['phone']} — API error\n";
        continue;
    }

    $data = json_decode($resp, true);
    $deals = $data['result'] ?? [];

    if (empty($deals)) {
        echo "  #{$r['id']} phone={$r['phone']} — not found in Bitrix24\n";
        $notFound++;
        continue;
    }

    // Берём сделку, созданную ближе всего ко времени заявки
    $consCreated = strtotime($r['created_at']);
    usort($deals, function($a, $b) use ($consCreated) {
        return abs(strtotime($a['DATE_CREATE']) - $consCreated)
             - abs(strtotime($b['DATE_CREATE']) - $consCreated);
    });
    $deal = $deals[0];

    $dealId = (int)$deal['ID'];
    $stage  = $deal['STAGE_ID'] ?: $stageNew;

    echo "  #{$r['id']} phone={$r['phone']} → deal #{$dealId} (stage {$stage}, created {$deal['DATE_CREATE']})\n";
    $matched++;

    if (!$dryRun) {
        $dbObj->update('course_consultations', [
            'bitrix_lead_id' => $dealId,
            'bitrix_stage' => $stage,
            'bitrix_stage_updated_at' => date('Y-m-d H:i:s'),
        ], 'id = ?', [$r['id']]);
    }
}

echo "\nMatched: {$matched}, Not found: {$notFound}\n";

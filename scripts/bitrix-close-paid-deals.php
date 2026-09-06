#!/usr/bin/env php
<?php
/**
 * Разовый бэкфилл: перевести оплаченные на сайте сделки с этапа «Оплаченная сделка»
 * (C108:UC_8RO3WZ) на «Сделка успешна» (C108:WON).
 *
 * Зачем: этап «Оплаченная сделка» стоит в воронке ПОСЛЕ WON/LOSE и не имеет семантики
 * успеха — Битрикс не считал такие сделки выигранными, воронка CRM по курсам была
 * занижена относительно РНП. Новые оплаты с 01.09.2026 идут сразу в WON
 * (BITRIX24_COURSE_STAGE_PAID), а уже накопленные сделки закрывает этот скрипт.
 *
 * Двигает только сделки, у которых:
 *   - есть заявка на курс с успешной оплатой на сайте (orders.payment_status='succeeded');
 *   - сделка живёт в воронке «Курсы» (108);
 *   - текущий этап — ровно BITRIX24_COURSE_STAGE_PAID_LEGACY.
 * Всё остальное (чужие воронки, ручные этапы менеджера) не трогает.
 *
 * По умолчанию — dry-run. Боевой прогон: --apply
 *
 *   php scripts/bitrix-close-paid-deals.php            # показать, что будет сделано
 *   php scripts/bitrix-close-paid-deals.php --apply    # перевести сделки
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/Bitrix24Integration.php';

$opts  = getopt('', ['apply']);
$apply = isset($opts['apply']);

$targetStage = defined('BITRIX24_COURSE_STAGE_PAID') ? BITRIX24_COURSE_STAGE_PAID : 'C108:WON';
$legacyStage = defined('BITRIX24_COURSE_STAGE_PAID_LEGACY') ? BITRIX24_COURSE_STAGE_PAID_LEGACY : 'C108:UC_8RO3WZ';
$pipeline    = defined('BITRIX24_COURSE_PIPELINE_ID') ? (int)BITRIX24_COURSE_PIPELINE_ID : 108;

$dbObj  = new Database($db);
$bitrix = new Bitrix24Integration();
if (!$bitrix->isConfigured()) {
    fwrite(STDERR, "Bitrix24 не настроен\n");
    exit(1);
}

$rows = $dbObj->query(
    "SELECT DISTINCT ce.id, ce.bitrix_lead_id, SUM(oi.price) AS amount
     FROM course_enrollments ce
     JOIN order_items oi ON oi.course_enrollment_id = ce.id
     JOIN orders o ON o.id = oi.order_id
     WHERE o.payment_status = 'succeeded'
       AND ce.bitrix_lead_id IS NOT NULL
     GROUP BY ce.id, ce.bitrix_lead_id
     ORDER BY ce.id"
);

echo ($apply ? 'ПРОГОН' : 'DRY-RUN') . ": оплаченных заявок со сделкой — " . count($rows) . "\n";

$moved = 0;
$skipped = 0;
foreach ($rows as $r) {
    $dealId = (int)$r['bitrix_lead_id'];
    $deal = $bitrix->getDeal($dealId);
    if (!$deal) {
        echo "  SKIP  сделка #{$dealId} не получена (заявка #{$r['id']})\n";
        $skipped++;
        continue;
    }

    $stage = (string)($deal['STAGE_ID'] ?? '');
    $cat   = (int)($deal['CATEGORY_ID'] ?? -1);
    if ($cat !== $pipeline || $stage !== $legacyStage) {
        $skipped++;
        continue;
    }

    printf("  %s сделка #%d (заявка #%d, %.0f ₽): %s → %s\n",
        $apply ? 'MOVE ' : 'WOULD', $dealId, (int)$r['id'], (float)$r['amount'], $stage, $targetStage);

    if (!$apply) {
        $moved++;
        continue;
    }

    if ($bitrix->moveDeal((string)$dealId, $targetStage)) {
        $dbObj->update(
            'course_enrollments',
            ['bitrix_stage' => $targetStage, 'bitrix_stage_updated_at' => date('Y-m-d H:i:s')],
            'id = ?',
            [(int)$r['id']]
        );
        $moved++;
    } else {
        echo "  FAIL  сделка #{$dealId} не переведена\n";
    }
    usleep(300000); // 2 запроса/сек — лимит Bitrix REST
}

echo ($apply ? "Переведено" : "Будет переведено") . ": {$moved} | пропущено (чужая воронка / другой этап): {$skipped}\n";

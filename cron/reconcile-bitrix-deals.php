#!/usr/bin/env php
<?php
/**
 * Cron: ежедневная сверка Bitrix24 ↔ сайт по курсовым сделкам.
 *
 * Зачем: выигранная в CRM сделка может не дойти до отчётов сайта (менеджер перенёс
 * её в другую воронку, заявка сменила статус и выпала из выборки sync-скрипта,
 * робот не проставил источник). Раньше такие продажи находились только вручную —
 * «в Битриксе сделок больше, чем в РНП». Теперь расхождение ищется само:
 *   1) по WON-сделкам без оплаты на сайте создаётся синтетический заказ
 *      (та же материализация, что в sync-course-deal-stages.php, но без ограничений
 *      по статусу заявки и 90-дневного окна);
 *   2) всё, что материализовать нельзя (консультации без заявки, сделки с нулевой
 *      суммой, сделки вообще без записи на сайте), логируется и уходит алертом
 *      в Telegram — с суммой и ссылками на сделки.
 *
 * Дефолт — окно 120 дней (менеджер закрывает рассрочки с большой задержкой).
 *
 * Флаги:
 *   --from=Y-m-d --to=Y-m-d   произвольный период (для разбора истории)
 *   --days=N                  окно в днях от сегодня (по умолчанию 120)
 *   --dry-run                 только показать расхождения, ничего не создавать
 *   --quiet                   без алерта в Telegram
 *
 * Crontab (раз в сутки):
 *   20 6 * * * docker exec pedagogy_web php /var/www/html/cron/reconcile-bitrix-deals.php >> /var/log/reconcile-bitrix-deals.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

set_time_limit(0);
define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/BitrixDealReconciliation.php';
require_once BASE_PATH . '/classes/TelegramNotifier.php';

$opts = getopt('', ['from::', 'to::', 'days::', 'dry-run', 'quiet']);
$dryRun = isset($opts['dry-run']);
$quiet  = isset($opts['quiet']);
$days   = isset($opts['days']) ? max(1, (int)$opts['days']) : 120;
$dateTo   = $opts['to']   ?? date('Y-m-d');
$dateFrom = $opts['from'] ?? date('Y-m-d', strtotime("-{$days} days", strtotime($dateTo)));

$lockFile = '/tmp/reconcile_bitrix_deals_cron.lock';
if (file_exists($lockFile)) {
    if (time() - filemtime($lockFile) > 3600) {
        unlink($lockFile);
    } else {
        echo date('Y-m-d H:i:s') . " - Another instance is running. Exiting.\n";
        exit(0);
    }
}
file_put_contents($lockFile, getmypid());

$logFile = BASE_PATH . '/logs/reconcile-bitrix-deals.log';
if (!is_dir(dirname($logFile))) {
    mkdir(dirname($logFile), 0755, true);
}
function log_line(string $msg): void {
    global $logFile;
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
    echo $line;
    error_log($line, 3, $logFile);
}

$dealUrl = fn(int $id) => "https://eduregion.bitrix24.ru/crm/deal/details/{$id}/";

try {
    $recon  = new BitrixDealReconciliation($db);
    $report = $recon->report($dateFrom, $dateTo);

    if (!$report['available']) {
        // Молчать нельзя: недоступный Bitrix выглядит как «расхождений нет».
        log_line("BITRIX_UNAVAILABLE | сверка за {$dateFrom}..{$dateTo} не выполнена");
        if (!$quiet) {
            TelegramNotifier::instance($db)->alert(
                'bitrix_recon_unavailable',
                'Сверка Bitrix ↔ сайт не выполнена',
                ['Период' => "{$dateFrom} — {$dateTo}", 'Причина' => 'Bitrix24 API недоступен'],
                'warning'
            );
        }
        exit(0);
    }

    $t = $report['totals'];
    log_line(sprintf(
        'SCAN | %s..%s | WON: %d (%.0f ₽) | в orders: %d | только CRM-слой: %d | не видно нигде: %d | оплачено на сайте без WON-сделки: %d',
        $dateFrom, $dateTo, $t['won_count'], $t['won_amount'],
        $t['in_orders_count'], $t['crm_layer_count'], $t['lost_count'], $t['not_won_count']
    ));

    // 1) Материализация: WON-сделка без оплаты на сайте → синтетический заказ.
    $created = [];
    if ($dryRun) {
        foreach ($report['missing'] as $m) {
            if (!empty($m['can_materialize'])) {
                log_line(sprintf('DRY_RUN | сделка #%d (%.0f ₽) → создался бы заказ по заявке #%d', $m['deal_id'], $m['amount'], $m['entity_id']));
            }
        }
    } else {
        $created = $recon->materialize($report['missing']);
        foreach ($created as $c) {
            log_line(sprintf(
                'OFFLINE_ORDER | сделка #%d | заявка #%d | заказ #%d | %.0f ₽ | %s',
                $c['deal_id'], $c['enrollment_id'], $c['order_id'], $c['amount'], $dealUrl($c['deal_id'])
            ));
        }
    }

    // 2) Что осталось за кадром отчётов после материализации.
    $createdIds = array_flip(array_column($created, 'deal_id'));
    $stillCrm   = [];
    $stillLost  = [];
    foreach ($report['missing'] as $m) {
        if (isset($createdIds[$m['deal_id']])) {
            continue;
        }
        if ($m['state'] === 'lost') {
            $stillLost[] = $m;
        } else {
            $stillCrm[] = $m;
        }
    }

    foreach ($stillLost as $m) {
        log_line(sprintf(
            'LOST | сделка #%d (%.0f ₽, %s, воронка %d, источник «%s») не видна ни в orders, ни в CRM-слое | %s | %s',
            $m['deal_id'], $m['amount'], $m['closedate'], $m['category'], $m['source'], $m['title'], $dealUrl($m['deal_id'])
        ));
    }
    foreach ($stillCrm as $m) {
        log_line(sprintf(
            'CRM_ONLY | сделка #%d (%.0f ₽, %s) учтена только оффлайн-слоем: %s | %s',
            $m['deal_id'], $m['amount'], $m['closedate'],
            $m['entity'] === 'consultation' ? 'консультация без заявки на курс' : ($m['amount'] <= 0 ? 'нулевая сумма сделки' : 'нет заявки на сайте'),
            $dealUrl($m['deal_id'])
        ));
    }
    foreach ($report['not_won'] as $n) {
        log_line(sprintf(
            'DEAL_NOT_WON | заявка #%d оплачена %s (%.0f ₽), сделка %s не закрыта в CRM (этап %s)',
            $n['enrollment_id'], $n['paid_date'], $n['amount'],
            $n['deal_id'] ? '#' . $n['deal_id'] : 'не привязана', $n['stage'] ?: '—'
        ));
    }

    // 3) Алерт — только когда есть что чинить руками.
    $lostAmount = array_sum(array_column($stillLost, 'amount'));
    if (!$quiet && ($stillLost || $created)) {
        $context = [
            'Период'          => "{$dateFrom} — {$dateTo}",
            'WON в Bitrix'    => sprintf('%d сделок на %.0f ₽', $t['won_count'], $t['won_amount']),
            'Досоздано заказов' => count($created) . ' на ' . sprintf('%.0f ₽', array_sum(array_column($created, 'amount'))),
            'Не видно в отчётах' => sprintf('%d сделок на %.0f ₽', count($stillLost), $lostAmount),
        ];
        foreach (array_slice($stillLost, 0, 5) as $m) {
            $context['Сделка #' . $m['deal_id']] = sprintf('%.0f ₽ — %s', $m['amount'], mb_substr($m['title'], 0, 60));
        }
        TelegramNotifier::instance($db)->alert(
            'bitrix_recon_gap',
            'Сверка Bitrix ↔ сайт: расхождение по курсам',
            $context,
            $stillLost ? 'critical' : 'info'
        );
    }

    log_line(sprintf(
        'DONE | создано заказов: %d | осталось невидимых: %d (%.0f ₽) | только CRM-слой: %d | сделок не закрыто менеджером: %d%s',
        count($created), count($stillLost), $lostAmount, count($stillCrm), $t['not_won_count'], $dryRun ? ' | DRY-RUN' : ''
    ));
} catch (Throwable $e) {
    log_line('FATAL: ' . $e->getMessage());
    error_log('Reconcile bitrix deals cron error: ' . $e->getMessage());
} finally {
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

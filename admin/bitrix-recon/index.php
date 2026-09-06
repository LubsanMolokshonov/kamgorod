<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * Сверка Bitrix24 ↔ сайт по курсовым сделкам: что выиграно в CRM, но не видно
 * в отчётах сайта, и что оплачено на сайте, но не закрыто в CRM.
 * Данные считаются на лету (BitrixDealReconciliation), автопочинку делает
 * cron/reconcile-bitrix-deals.php. Только просмотр.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/BitrixDealReconciliation.php';

$pageTitle = 'Сверка с Bitrix24';
$additionalCSS = ['/assets/css/admin-rnp.css?v=' . filemtime(__DIR__ . '/../../assets/css/admin-rnp.css')];

$dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-90 days'));
$dateTo   = $_GET['date_to']   ?? date('Y-m-d');
foreach (['dateFrom', 'dateTo'] as $v) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $$v)) {
        $$v = date('Y-m-d');
    }
}

$report = (new BitrixDealReconciliation($db))->report($dateFrom, $dateTo);
$t = $report['totals'];

$stateLabels = [
    'in_orders' => ['Есть заказ', 'br-badge--ok'],
    'crm_layer' => ['Только CRM-слой', 'br-badge--warn'],
    'lost'      => ['Не видно нигде', 'br-badge--bad'],
];

function brMoney($v): string {
    return number_format((float)$v, 0, ',', ' ') . ' ₽';
}
function brDealUrl(int $id): string {
    return 'https://eduregion.bitrix24.ru/crm/deal/details/' . $id . '/';
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.br-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.br-table th, .br-table td { padding: 8px 10px; border-bottom: 1px solid #eef0f3; text-align: left; }
.br-table th { font-weight: 600; color: #6b7280; font-size: 12px; text-transform: uppercase; letter-spacing: .03em; }
.br-table td.num, .br-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
.br-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; background: #f1f5f9; color: #475569; white-space: nowrap; }
.br-badge--ok { background: #dcfce7; color: #15803d; }
.br-badge--warn { background: #fef3c7; color: #b45309; }
.br-badge--bad { background: #fee2e2; color: #b91c1c; }
.br-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
.br-card { background: #fff; border: 1px solid #eef0f3; border-radius: 10px; padding: 14px 16px; }
.br-card .br-num { font-size: 22px; font-weight: 700; font-variant-numeric: tabular-nums; }
.br-card .br-lbl { font-size: 12px; color: #6b7280; margin-top: 2px; }
.br-card--bad .br-num { color: #b91c1c; }
.br-card--warn .br-num { color: #b45309; }
tr.br-row-bad td { background: #fef2f2; }
.br-note { color: #6b7280; font-size: 12px; }
</style>

<div class="page-header">
    <h1>Сверка с Bitrix24</h1>
    <p class="page-sub">
        Выигранные сделки воронки «ФГОС-Практикум (Курсы)» и наши сделки в ЦДО против оплат на сайте.
        Период по дате закрытия сделки: <?= htmlspecialchars($dateFrom) ?> — <?= htmlspecialchars($dateTo) ?>.
    </p>
</div>

<div class="content-card rnp-filters">
    <form method="GET">
        <div class="filter-row">
            <div class="filter-group">
                <label>Период</label>
                <div class="filter-dates">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <span>—</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
            </div>
            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
            </div>
        </div>
    </form>
</div>

<?php if (!$report['available']): ?>
    <div class="content-card rnp-card">
        <p style="color:#b91c1c;padding:8px 4px;">
            Bitrix24 недоступен — сверка не выполнена. Цифры не показываем, чтобы не принять сбой API за «расхождений нет».
        </p>
    </div>
<?php else: ?>

<div class="br-cards">
    <div class="br-card">
        <div class="br-num"><?= (int)$t['won_count'] ?></div>
        <div class="br-lbl">выиграно сделок на <?= brMoney($t['won_amount']) ?></div>
    </div>
    <div class="br-card">
        <div class="br-num"><?= (int)$t['in_orders_count'] ?></div>
        <div class="br-lbl">есть заказ на сайте — <?= brMoney($t['in_orders_amount']) ?></div>
    </div>
    <div class="br-card br-card--warn">
        <div class="br-num"><?= (int)$t['crm_layer_count'] ?></div>
        <div class="br-lbl">видно только оффлайн-слоем РНП — <?= brMoney($t['crm_layer_amount']) ?></div>
    </div>
    <div class="br-card br-card--bad">
        <div class="br-num"><?= (int)$t['lost_count'] ?></div>
        <div class="br-lbl">не видно нигде — <?= brMoney($t['lost_amount']) ?></div>
    </div>
    <div class="br-card">
        <div class="br-num"><?= (int)$t['not_won_count'] ?></div>
        <div class="br-lbl">оплачено на сайте, сделка в CRM не закрыта — <?= brMoney($t['not_won_amount']) ?></div>
    </div>
</div>

<div class="content-card rnp-card">
    <div class="rnp-card-header">
        <h2>Выигранные сделки</h2>
        <span class="rnp-card-meta">
            «Только CRM-слой» — выручка в РНП есть (строка «Курсы Другое»), но заказа на сайте нет.
            «Не видно нигде» — продажа выпала из всех отчётов, нужно разобрать вручную.
        </span>
    </div>
    <?php if (!$report['items']): ?>
        <p class="br-note" style="padding:8px 4px;">За период выигранных сделок нет.</p>
    <?php else: ?>
    <table class="br-table">
        <thead>
            <tr>
                <th>Сделка</th>
                <th>Закрыта</th>
                <th>Название</th>
                <th>Воронка</th>
                <th>Запись на сайте</th>
                <th class="num">Сумма сделки</th>
                <th class="num">Оплачено</th>
                <th>Состояние</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($report['items'] as $i):
            [$label, $cls] = $stateLabels[$i['state']]; ?>
            <tr<?= $i['state'] === 'lost' ? ' class="br-row-bad"' : '' ?>>
                <td><a href="<?= htmlspecialchars(brDealUrl((int)$i['deal_id'])) ?>" target="_blank" rel="noopener">#<?= (int)$i['deal_id'] ?></a></td>
                <td><?= htmlspecialchars($i['closedate']) ?></td>
                <td><?= htmlspecialchars(mb_substr($i['title'], 0, 70)) ?></td>
                <td><?= $i['category'] === 108 ? 'Курсы' : ($i['category'] === 4 ? 'ЦДО' : (int)$i['category']) ?></td>
                <td>
                    <?php if ($i['entity'] === 'enrollment'): ?>
                        заявка #<?= (int)$i['entity_id'] ?> (<?= htmlspecialchars((string)$i['status']) ?>)
                    <?php elseif ($i['entity'] === 'consultation'): ?>
                        консультация #<?= (int)$i['entity_id'] ?>
                    <?php else: ?>
                        <span class="br-note">нет</span>
                    <?php endif; ?>
                </td>
                <td class="num"><?= brMoney($i['amount']) ?></td>
                <td class="num"><?= $i['paid'] > 0 ? brMoney($i['paid']) : '—' ?></td>
                <td><span class="br-badge <?= $cls ?>"><?= $label ?></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<div class="content-card rnp-card">
    <div class="rnp-card-header">
        <h2>Оплачено на сайте, но сделка в CRM не закрыта</h2>
        <span class="rnp-card-meta">Деньги в отчётах сайта есть; занижена воронка Bitrix — менеджеру нужно закрыть сделку</span>
    </div>
    <?php if (!$report['not_won']): ?>
        <p class="br-note" style="padding:8px 4px;">Расхождений нет.</p>
    <?php else: ?>
    <table class="br-table">
        <thead>
            <tr><th>Заявка</th><th>Сделка</th><th>Оплата</th><th>Этап в CRM</th><th class="num">Сумма</th></tr>
        </thead>
        <tbody>
        <?php foreach ($report['not_won'] as $n): ?>
            <tr>
                <td>#<?= (int)$n['enrollment_id'] ?> (<?= htmlspecialchars($n['status']) ?>)</td>
                <td>
                    <?php if ($n['deal_id']): ?>
                        <a href="<?= htmlspecialchars(brDealUrl((int)$n['deal_id'])) ?>" target="_blank" rel="noopener">#<?= (int)$n['deal_id'] ?></a>
                    <?php else: ?>
                        <span class="br-note">не привязана</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($n['paid_date']) ?></td>
                <td><?= htmlspecialchars($n['stage'] ?: '—') ?></td>
                <td class="num"><?= brMoney($n['amount']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>

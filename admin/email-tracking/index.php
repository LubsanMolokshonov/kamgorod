<?php
/**
 * E-mail трекинг — дашборд сквозной аналитики email-рассылок.
 * Показывает: Отправлено → Открыто → Переходов → Оплат / Выручка
 * в разрезе направлений (journey/webinar/publication/...) и touchpoint'ов.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';
require_once __DIR__ . '/../../classes/EmailAnalytics.php';

$pageTitle = 'E-mail трекинг';
$additionalCSS = ['/assets/css/admin-email-tracking.css'];
$additionalJS  = ['/assets/js/admin-email-tracking.js'];

$analytics = new EmailAnalytics($db);

// Фильтры (по умолчанию — последние 30 дней)
$dateFrom  = $_GET['date_from']  ?? date('Y-m-d', strtotime('-30 days'));
$dateTo    = $_GET['date_to']    ?? date('Y-m-d');
$emailType = $_GET['email_type'] ?? 'all';

$filters = [
    'date_from'  => $dateFrom,
    'date_to'    => $dateTo,
    'email_type' => $emailType,
];

$totals      = $analytics->getTotals($filters);
$byType      = $analytics->getByType($filters);
$byTouchpoint = $analytics->getByTouchpoint($filters);
$recent      = $analytics->getRecent($filters, 50);
$allTypes    = EmailAnalytics::allTypes();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>📧 E-mail трекинг</h1>
    <p class="page-subtitle">Сквозная аналитика рассылок: открытия, переходы и оплаты по каждому письму.</p>
</div>

<div class="content-card email-filters">
    <form id="emailFilterForm" method="GET">
        <div class="filter-row">
            <div class="filter-group">
                <label>Период отправки</label>
                <div class="filter-dates">
                    <input type="date" name="date_from" value="<?= htmlspecialchars($dateFrom) ?>">
                    <span>—</span>
                    <input type="date" name="date_to" value="<?= htmlspecialchars($dateTo) ?>">
                </div>
            </div>

            <div class="filter-group">
                <label>Направление</label>
                <select name="email_type">
                    <option value="all" <?= $emailType === 'all' ? 'selected' : '' ?>>Все</option>
                    <?php foreach ($allTypes as $code => $label): ?>
                        <option value="<?= htmlspecialchars($code) ?>" <?= $emailType === $code ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-group filter-actions">
                <button type="submit" class="btn btn-primary">Применить</button>
                <a href="/admin/email-tracking/" class="btn btn-secondary">Сбросить</a>
            </div>
        </div>
    </form>
</div>

<!-- KPI -->
<div class="email-kpi-grid">
    <div class="kpi-card">
        <div class="kpi-label">Отправлено</div>
        <div class="kpi-value"><?= number_format((int)$totals['sent'], 0, ',', ' ') ?></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Открыто</div>
        <div class="kpi-value"><?= number_format((int)$totals['opened'], 0, ',', ' ') ?></div>
        <div class="kpi-sub">Open rate: <strong><?= $totals['open_rate'] ?>%</strong></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Переходов</div>
        <div class="kpi-value"><?= number_format((int)$totals['clicked'], 0, ',', ' ') ?></div>
        <div class="kpi-sub">CTR: <strong><?= $totals['click_rate'] ?>%</strong> · CTOR: <?= $totals['ctor'] ?>%</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-label">Оплат</div>
        <div class="kpi-value"><?= number_format((int)$totals['paid'], 0, ',', ' ') ?></div>
        <div class="kpi-sub">От переходов: <strong><?= $totals['conv_rate'] ?>%</strong> · От отправок: <?= $totals['overall_conv'] ?>%</div>
    </div>
    <div class="kpi-card kpi-revenue">
        <div class="kpi-label">Выручка</div>
        <div class="kpi-value"><?= number_format($totals['revenue'], 0, ',', ' ') ?> ₽</div>
    </div>
</div>

<!-- По направлениям -->
<div class="content-card">
    <h2>По направлениям</h2>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Направление</th>
                    <th class="num">Отправлено</th>
                    <th class="num">Открыто</th>
                    <th class="num">Open %</th>
                    <th class="num">Переходов</th>
                    <th class="num">CTR %</th>
                    <th class="num">Оплат</th>
                    <th class="num">Конв. %</th>
                    <th class="num">Выручка</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($byType)): ?>
                    <tr><td colspan="9" class="empty">Нет данных за выбранный период.</td></tr>
                <?php else: foreach ($byType as $r): ?>
                    <tr>
                        <td>
                            <a href="?date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>&email_type=<?= urlencode($r['email_type']) ?>">
                                <?= htmlspecialchars($r['type_label']) ?>
                            </a>
                        </td>
                        <td class="num"><?= number_format((int)$r['sent'], 0, ',', ' ') ?></td>
                        <td class="num"><?= number_format((int)$r['opened'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['open_rate'] ?>%</td>
                        <td class="num"><?= number_format((int)$r['clicked'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['click_rate'] ?>%</td>
                        <td class="num"><?= number_format((int)$r['paid'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['overall_conv'] ?>%</td>
                        <td class="num"><?= number_format((float)$r['revenue'], 0, ',', ' ') ?> ₽</td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- По touchpoint'ам -->
<div class="content-card">
    <h2>По touchpoint'ам<?php if ($emailType !== 'all'): ?> — <?= htmlspecialchars(EmailAnalytics::typeLabel($emailType)) ?><?php endif; ?></h2>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Направление</th>
                    <th>Touchpoint</th>
                    <th class="num">Отправлено</th>
                    <th class="num">Открыто</th>
                    <th class="num">Open %</th>
                    <th class="num">Переходов</th>
                    <th class="num">CTR %</th>
                    <th class="num">Оплат</th>
                    <th class="num">Конв. %</th>
                    <th class="num">Выручка</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($byTouchpoint)): ?>
                    <tr><td colspan="10" class="empty">Нет данных за выбранный период.</td></tr>
                <?php else: foreach ($byTouchpoint as $r): ?>
                    <tr>
                        <td><?= htmlspecialchars($r['type_label']) ?></td>
                        <td><code><?= htmlspecialchars($r['touchpoint_code']) ?></code></td>
                        <td class="num"><?= number_format((int)$r['sent'], 0, ',', ' ') ?></td>
                        <td class="num"><?= number_format((int)$r['opened'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['open_rate'] ?>%</td>
                        <td class="num"><?= number_format((int)$r['clicked'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['click_rate'] ?>%</td>
                        <td class="num"><?= number_format((int)$r['paid'], 0, ',', ' ') ?></td>
                        <td class="num"><?= $r['overall_conv'] ?>%</td>
                        <td class="num"><?= number_format((float)$r['revenue'], 0, ',', ' ') ?> ₽</td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Последние письма -->
<div class="content-card">
    <h2>Последние 50 писем</h2>
    <div class="table-wrapper">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Отправлено</th>
                    <th>Направление</th>
                    <th>Touchpoint</th>
                    <th>Получатель</th>
                    <th>Тема</th>
                    <th class="num">Открытий</th>
                    <th class="num">Кликов</th>
                    <th>Оплата</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent)): ?>
                    <tr><td colspan="8" class="empty">Нет писем за выбранный период.</td></tr>
                <?php else: foreach ($recent as $e): ?>
                    <tr>
                        <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($e['sent_at']))) ?></td>
                        <td><?= htmlspecialchars($e['type_label']) ?></td>
                        <td><code><?= htmlspecialchars((string)($e['touchpoint_code'] ?? '')) ?></code></td>
                        <td><?= htmlspecialchars($e['recipient_email']) ?></td>
                        <td class="subject-col" title="<?= htmlspecialchars((string)($e['subject'] ?? '')) ?>">
                            <?= htmlspecialchars(mb_substr((string)($e['subject'] ?? ''), 0, 60)) ?>
                        </td>
                        <td class="num"><?= (int)$e['opens_count'] ?></td>
                        <td class="num"><?= (int)$e['clicks_count'] ?></td>
                        <td>
                            <?php if ($e['order_id']): ?>
                                <span class="tag tag-success">✓ <?= number_format((float)($e['revenue'] ?? 0), 0, ',', ' ') ?> ₽</span>
                            <?php else: ?>
                                <span class="tag tag-muted">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

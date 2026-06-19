<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard
/**
 * A/B-тесты — дашборд эксперимента «модель оплаты».
 *
 * Сравнивает вариант A (control, поштучная оплата) и вариант B (subscription, только
 * подписка): назначено пользователей, платящих, заказов, выручка, ARPU, подписок.
 * Источник истины — users.pricing_variant и orders.pricing_variant (миграции 151–152).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'A/B-тесты';

$variants = ['A', 'B'];
$labels   = ['A' => 'A · поштучно (control)', 'B' => 'B · подписка (subscription)'];

// Назначено пользователей по варианту.
$usersByVariant = $db->query("
    SELECT pricing_variant AS v, COUNT(*) AS cnt
    FROM users WHERE pricing_variant IS NOT NULL
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Успешные заказы и выручка по варианту (все типы).
$ordersByVariant = $db->query("
    SELECT pricing_variant AS v, COUNT(*) AS cnt, COALESCE(SUM(final_amount),0) AS revenue
    FROM orders
    WHERE pricing_variant IS NOT NULL AND payment_status = 'succeeded'
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_ASSOC);
$orders = [];
foreach ($ordersByVariant as $r) { $orders[$r['v']] = $r; }

// Платящие пользователи (заказ > 0 ₽) по варианту.
$payingByVariant = $db->query("
    SELECT pricing_variant AS v, COUNT(DISTINCT user_id) AS cnt
    FROM orders
    WHERE pricing_variant IS NOT NULL AND payment_status = 'succeeded' AND final_amount > 0
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Оформлено подписок по варианту.
$subsByVariant = $db->query("
    SELECT pricing_variant AS v, COUNT(*) AS cnt, COALESCE(SUM(final_amount),0) AS revenue
    FROM orders
    WHERE pricing_variant IS NOT NULL AND payment_status = 'succeeded' AND subscription_plan_id IS NOT NULL
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_ASSOC);
$subs = [];
foreach ($subsByVariant as $r) { $subs[$r['v']] = $r; }

// Выдано документов за 0 ₽ (подписчику) по варианту.
$freeDocsByVariant = $db->query("
    SELECT pricing_variant AS v, COUNT(*) AS cnt
    FROM orders
    WHERE pricing_variant IS NOT NULL AND payment_status = 'succeeded'
          AND subscription_plan_id IS NULL AND final_amount = 0
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_KEY_PAIR);

function money($v) { return number_format((float)$v, 0, ',', ' ') . ' ₽'; }

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>A/B-тест: модель оплаты</h1>
    <p>
        Статус эксперимента:
        <strong style="color:<?php echo PRICING_AB_ACTIVE ? '#16a34a' : '#a01030'; ?>;">
            <?php echo PRICING_AB_ACTIVE ? 'АКТИВЕН — трафик делится 50/50' : 'ВЫКЛЮЧЕН — весь трафик в варианте A'; ?>
        </strong>
        · переключается переменной <code>PRICING_AB_ENABLED</code> в <code>.env</code>
    </p>
</div>

<div style="display:grid;grid-template-columns:repeat(2,1fr);gap:20px;margin-bottom:24px;">
    <?php foreach ($variants as $v):
        $u   = (int)($usersByVariant[$v] ?? 0);
        $ord = $orders[$v] ?? ['cnt' => 0, 'revenue' => 0];
        $pay = (int)($payingByVariant[$v] ?? 0);
        $sub = $subs[$v] ?? ['cnt' => 0, 'revenue' => 0];
        $free = (int)($freeDocsByVariant[$v] ?? 0);
        $arpu = $u > 0 ? $ord['revenue'] / $u : 0;
        $conv = $u > 0 ? round($pay / $u * 100, 2) : 0;
        $isB = $v === 'B';
    ?>
    <div class="content-card" style="border-top:4px solid <?php echo $isB ? '#6c5ce7' : '#0ea5e9'; ?>;">
        <div class="card-body">
            <h2 style="margin:0 0 14px;font-size:18px;"><?php echo htmlspecialchars($labels[$v]); ?></h2>
            <table class="admin-table">
                <tbody>
                    <tr><td>Назначено пользователей</td><td style="text-align:right;font-weight:700;"><?php echo number_format($u, 0, ',', ' '); ?></td></tr>
                    <tr><td>Платящих (заказ &gt; 0 ₽)</td><td style="text-align:right;font-weight:700;"><?php echo number_format($pay, 0, ',', ' '); ?></td></tr>
                    <tr><td>Конверсия в платящего</td><td style="text-align:right;font-weight:700;"><?php echo $conv; ?>%</td></tr>
                    <tr><td>Успешных заказов</td><td style="text-align:right;"><?php echo number_format((int)$ord['cnt'], 0, ',', ' '); ?></td></tr>
                    <tr><td>Выручка всего</td><td style="text-align:right;font-weight:700;"><?php echo money($ord['revenue']); ?></td></tr>
                    <tr><td>ARPU (выручка / назначено)</td><td style="text-align:right;font-weight:700;"><?php echo money($arpu); ?></td></tr>
                    <tr><td>Оформлено подписок</td><td style="text-align:right;"><?php echo number_format((int)$sub['cnt'], 0, ',', ' '); ?> · <?php echo money($sub['revenue']); ?></td></tr>
                    <tr><td>Выдано документов за 0 ₽</td><td style="text-align:right;"><?php echo number_format($free, 0, ',', ' '); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="content-card">
    <div class="card-body" style="color:#555;font-size:13px;line-height:1.6;">
        <strong>Как читать.</strong> Вариант <strong>A</strong> — текущая модель (каждый документ/пакет токенов
        оплачивается отдельно). Вариант <strong>B</strong> — документы и токены доступны только по подписке.
        Главные метрики сравнения — <strong>ARPU</strong> и <strong>выручка всего</strong> на одинаковом объёме
        трафика (50/50). В Яндекс.Метрике те же когорты доступны через параметр визита
        <code>pricing_ab = control | subscription</code> и метку <code>pm-*</code> в купоне ecommerce.
        Заказы можно отфильтровать по варианту в разделе <a href="/admin/orders/">Заказы</a>.
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

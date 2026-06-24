<?php
/**
 * Партиал блока тарифов подписки (Базовый / Про) с переключателем месяц/год.
 *
 * Используется на лендинге /podpiska/ (pages/subscription.php) и встраивается прямо в
 * корзину как ОСНОВНОЙ способ оплаты для варианта B A/B-теста (subscription-only).
 *
 * Перед include можно задать (все опционально):
 *   $plansHeading — заголовок над карточками (например, в корзине);
 *   $plansIntro   — пояснение под заголовком.
 *
 * Требует глобальный $db (PDO) и доступный generateCSRFToken() (includes/session.php).
 * Планы подтягивает сам, если не передан массив $plans.
 *
 * Кнопка покупки создаёт Yookassa-платёж через ajax/create-subscription-payment.php;
 * гость уводится на /vhod. Подписка АДДИТИВНА — разовые покупки остаются доступны.
 */

require_once __DIR__ . '/../classes/Database.php';

if (!isset($plans) || !is_array($plans)) {
    $plans = (new Database($GLOBALS['db']))->query(
        "SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC"
    );
}

$spUserId = $_SESSION['user_id'] ?? null;
$spCsrf   = generateCSRFToken();

// Что входит в тарифы (для карточек).
$spFeatureRows = [
    'certs'      => 'Дипломы конкурсов, сертификаты вебинаров, свидетельства о публикациях — без доплат',
    'portfolio'  => 'Документы для портфолио аттестации — сколько нужно',
    'generator'  => 'Генератор материалов ФОП',
    'courses'    => 'Курсы повышения квалификации и переподготовки',
    'aibot'      => 'AI-помощник для педагогов',
];
?>
<style>
.sub-toggle{display:inline-flex;background:#eef0f7;border-radius:999px;padding:4px;margin:18px 0 28px;}
.sub-toggle button{border:0;background:transparent;padding:9px 22px;border-radius:999px;font-weight:600;cursor:pointer;color:#5b6178;font-size:15px;}
.sub-toggle button.active{background:#fff;color:#1c2033;box-shadow:0 1px 4px rgba(0,0,0,.08);}
.sub-toggle .save{color:#16a34a;font-size:12px;margin-left:6px;}
.sub-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;align-items:start;}
@media(max-width:720px){.sub-grid{grid-template-columns:1fr;}}
.sub-card{border:1px solid #e4e7f0;border-radius:18px;padding:28px 26px;background:#fff;position:relative;}
.sub-card.pro{border-color:#6c5ce7;box-shadow:0 8px 30px rgba(108,92,231,.12);}
.sub-tag{position:absolute;top:-12px;left:26px;background:#6c5ce7;color:#fff;font-size:12px;font-weight:700;padding:4px 12px;border-radius:999px;}
.sub-name{font-size:22px;font-weight:700;color:#1c2033;}
.sub-price{font-size:36px;font-weight:800;color:#1c2033;margin:10px 0 2px;}
.sub-price span{font-size:16px;font-weight:600;color:#8b90a8;}
.sub-price-year{font-size:13px;color:#16a34a;font-weight:600;min-height:18px;}
.sub-feats{list-style:none;padding:0;margin:20px 0 24px;}
.sub-feats li{display:flex;gap:10px;align-items:flex-start;padding:7px 0;color:#3a3f54;font-size:15px;line-height:1.4;border-top:1px solid #f1f2f8;}
.sub-feats li:first-child{border-top:0;}
.sub-feats .ic{flex:0 0 20px;font-weight:700;}
.sub-feats .yes{color:#16a34a;}
.sub-feats .no{color:#cbd0de;}
.sub-feats .muted{color:#9aa0b4;}
.sub-cta{display:block;width:100%;text-align:center;border:0;border-radius:12px;padding:14px;font-weight:700;font-size:16px;cursor:pointer;}
.sub-cta.primary{background:#6c5ce7;color:#fff;}
.sub-cta.ghost{background:#f0f1f8;color:#2b2f44;}
.sub-note{color:#8b90a8;font-size:13px;margin-top:18px;line-height:1.5;}
.sub-block-head{margin-bottom:6px;}
.sub-block-head h2{font-size:24px;font-weight:800;color:#1c2033;margin:0 0 8px;}
.sub-block-head p{color:#5b6178;font-size:15px;line-height:1.5;margin:0;}
@media(max-width:560px){
    .sub-block-head h2{font-size:19px;line-height:1.25;}
    .sub-block-head p{font-size:14px;}
    .sub-toggle{display:flex;width:100%;margin:14px 0 18px;}
    .sub-toggle button{flex:1;padding:10px 8px;font-size:14px;}
    .sub-toggle .save{font-size:11px;margin-left:4px;}
    .sub-grid{gap:16px;}
    .sub-card{padding:20px 18px;border-radius:16px;}
    .sub-card.pro{order:-1;}
    .sub-name{font-size:19px;}
    .sub-price{font-size:30px;}
    .sub-price span{font-size:14px;}
    .sub-feats{margin:16px 0 18px;}
    .sub-feats li{font-size:14px;padding:6px 0;}
    .sub-cta{padding:13px;font-size:15px;}
    .sub-note{font-size:12px;margin-top:14px;}
    .sub-note-more{display:none;}
}
</style>

<?php if (!empty($plansHeading)): ?>
<div class="sub-block-head">
    <h2><?= htmlspecialchars($plansHeading, ENT_QUOTES, 'UTF-8') ?></h2>
    <?php if (!empty($plansIntro)): ?>
        <p><?= htmlspecialchars($plansIntro, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</div>
<?php endif; ?>

<div style="text-align:center;">
    <div class="sub-toggle" role="tablist">
        <button type="button" id="t-monthly" class="active" onclick="setPeriod('monthly')">Помесячно</button>
        <button type="button" id="t-yearly" onclick="setPeriod('yearly')">На год <span class="save">−2 месяца</span></button>
    </div>
</div>

<div class="sub-grid">
    <?php foreach ($plans as $plan):
        $isPro = $plan['slug'] === 'pro';
        $unlimited = $plan['monthly_generation_tokens'] === null;
        $tokens = (int)$plan['monthly_generation_tokens'];
        $courseDisc = (int)$plan['course_discount_percent'];
    ?>
        <div class="sub-card <?= $isPro ? 'pro' : '' ?>">
            <?php if ($isPro): ?><div class="sub-tag">Выгоднее всего</div><?php endif; ?>
            <div class="sub-name"><?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?></div>

            <div class="sub-price"
                 data-monthly="<?= number_format((float)$plan['price_monthly'], 0, '', ' ') ?>"
                 data-yearly="<?= number_format((float)$plan['price_yearly'], 0, '', ' ') ?>">
                <?= number_format((float)$plan['price_monthly'], 0, '', ' ') ?> <span>₽/мес</span>
            </div>
            <div class="sub-price-year"
                 data-yearprice="<?= number_format((float)$plan['price_yearly'], 0, '', ' ') ?>"></div>

            <ul class="sub-feats">
                <li><span class="ic yes">✓</span><span><?= htmlspecialchars($spFeatureRows['certs'], ENT_QUOTES, 'UTF-8') ?></span></li>
                <li><span class="ic yes">✓</span><span><?= htmlspecialchars($spFeatureRows['portfolio'], ENT_QUOTES, 'UTF-8') ?></span></li>
                <li>
                    <span class="ic yes">✓</span>
                    <span><?= htmlspecialchars($spFeatureRows['generator'], ENT_QUOTES, 'UTF-8') ?>:
                        <strong><?= $unlimited ? 'безлимит' : ('≈ ' . max(1, (int)round($tokens / 15)) . ' материалов в месяц') ?></strong>
                    </span>
                </li>
                <li>
                    <span class="ic <?= $courseDisc > 0 ? 'yes' : 'no' ?>"><?= $courseDisc > 0 ? '✓' : '—' ?></span>
                    <span class="<?= $courseDisc > 0 ? '' : 'muted' ?>">
                        <?= htmlspecialchars($spFeatureRows['courses'], ENT_QUOTES, 'UTF-8') ?>:
                        <?= $courseDisc > 0 ? ('скидка ' . $courseDisc . '%') : 'без скидки' ?>
                    </span>
                </li>
                <li>
                    <span class="ic muted">⏳</span>
                    <span class="muted"><?= htmlspecialchars($spFeatureRows['aibot'], ENT_QUOTES, 'UTF-8') ?> — скоро<?= $isPro ? '' : ' (в тарифе Про)' ?></span>
                </li>
            </ul>

            <button type="button"
                    class="sub-cta <?= $isPro ? 'primary' : 'ghost' ?> sub-buy"
                    data-plan="<?= htmlspecialchars($plan['slug'], ENT_QUOTES, 'UTF-8') ?>">
                Оформить «<?= htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8') ?>»
            </button>
        </div>
    <?php endforeach; ?>
</div>

<div style="text-align:center;margin-top:22px;">
    <label style="display:inline-flex;align-items:flex-start;gap:10px;max-width:520px;text-align:left;cursor:pointer;color:#3a3f54;font-size:14px;line-height:1.5;">
        <input type="checkbox" id="sub-autorenew" checked
               style="margin-top:3px;width:18px;height:18px;flex:0 0 18px;accent-color:#6c5ce7;cursor:pointer;">
        <span>Автоматически продлевать подписку. Спишем стоимость выбранного периода с привязанной
        карты, когда срок закончится. Отменить автопродление можно в любой момент в личном кабинете.</span>
    </label>
</div>

<p class="sub-note">
    Подписка продлевается автоматически на выбранный период (месяц или год).<span class="sub-note-more">
    Документы, оформленные в период действия подписки, остаются у вас навсегда. Подписка не
    отменяет возможность покупать отдельные мероприятия и пакеты токенов.</span>
</p>

<input type="hidden" id="sub-csrf" value="<?= htmlspecialchars($spCsrf, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="sub-logged" value="<?= $spUserId ? '1' : '0' ?>">

<script>
var subPeriod = 'monthly';
function setPeriod(p) {
    subPeriod = p;
    document.getElementById('t-monthly').classList.toggle('active', p === 'monthly');
    document.getElementById('t-yearly').classList.toggle('active', p === 'yearly');
    document.querySelectorAll('.sub-price').forEach(function (el) {
        if (p === 'yearly') {
            el.innerHTML = el.dataset.yearly + ' <span>₽/год</span>';
        } else {
            el.innerHTML = el.dataset.monthly + ' <span>₽/мес</span>';
        }
    });
    document.querySelectorAll('.sub-price-year').forEach(function (el) {
        el.textContent = p === 'yearly' ? '' : ('или ' + el.dataset.yearprice + ' ₽ при оплате за год');
    });
}
setPeriod('monthly');

document.querySelectorAll('.sub-buy').forEach(function (btn) {
    btn.addEventListener('click', function () {
        if (document.getElementById('sub-logged').value !== '1') {
            window.location.href = '/vhod?return=' + encodeURIComponent(window.location.pathname + window.location.search);
            return;
        }
        var orig = btn.textContent;
        btn.disabled = true; btn.style.opacity = '0.6'; btn.textContent = 'Создаём платёж…';
        var arEl = document.getElementById('sub-autorenew');
        var fd = new FormData();
        fd.append('csrf', document.getElementById('sub-csrf').value);
        fd.append('plan_slug', btn.dataset.plan);
        fd.append('period', subPeriod);
        fd.append('auto_renew', (!arEl || arEl.checked) ? '1' : '0');
        fetch('/ajax/create-subscription-payment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success && res.confirmation_url) { window.location.href = res.confirmation_url; return; }
                if (res.code === 'unauthorized') { window.location.href = '/vhod?return=' + encodeURIComponent(window.location.pathname + window.location.search); return; }
                alert(res.error || 'Не удалось создать платёж');
                btn.disabled = false; btn.style.opacity = '1'; btn.textContent = orig;
            })
            .catch(function () {
                alert('Сеть прервалась, попробуйте ещё раз');
                btn.disabled = false; btn.style.opacity = '1'; btn.textContent = orig;
            });
    });
});
</script>

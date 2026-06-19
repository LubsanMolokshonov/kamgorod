<?php
/**
 * Лендинг подписки — /podpiska/
 *
 * Две карточки тарифов (Базовый / Про), переключатель месяц/год.
 * Кнопка оформления создаёт Yookassa-платёж через ajax/create-subscription-payment.php.
 * Подписка АДДИТИВНА: разовые покупки дипломов/сертификатов остаются доступны всем.
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../includes/session.php';

$dbh = new Database($db);
$plans = $dbh->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC");

$userId = $_SESSION['user_id'] ?? null;
$activeSub = $userId ? (new SubscriptionService($db))->getActiveSubscription((int)$userId) : null;

$csrf = generateCSRFToken();

// Что входит в тарифы (для карточек).
$featureRows = [
    'certs'      => 'Дипломы конкурсов, сертификаты вебинаров, свидетельства о публикациях — без доплат',
    'portfolio'  => 'Документы для портфолио аттестации — сколько нужно',
    'generator'  => 'Генератор материалов ФОП',
    'courses'    => 'Курсы повышения квалификации и переподготовки',
    'aibot'      => 'AI-помощник для педагогов',
];

$pageTitle = 'Подписка для педагога — все документы и материалы | ' . SITE_NAME;
$pageDescription = 'Подписка fgos.pro: безлимит дипломов и сертификатов для портфолио аттестации, генератор материалов ФОП и скидка на курсы.';
$canonicalUrl = SITE_URL . '/podpiska/';
$rdActivePage = 'podpiska';

include __DIR__ . '/../includes/header-redesign.php';
?>

<style>
.sub-wrap{max-width:1000px;margin:0 auto;padding:0 16px;}
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
.sub-active-banner{background:#eafbf0;border:1px solid #b7ecc8;border-radius:14px;padding:16px 20px;margin:18px 0;color:#13703a;}
.sub-note{color:#8b90a8;font-size:13px;margin-top:18px;line-height:1.5;}
</style>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:10px;">Подписка для педагога</h1>
    <p style="color:#5b6178;max-width:640px;margin-top:10px;font-size:17px;">
      Все документы для портфолио аттестации без доплат и материалы ФОП в одной подписке.
      Разовые покупки остаются доступны — подписка просто выгоднее, если вы оформляете
      больше двух документов в год.
    </p>
  </div>
</section>

<section style="padding:30px 0 60px;">
  <div class="sub-wrap">

    <?php if ($activeSub): ?>
      <div class="sub-active-banner">
        У вас активна подписка <strong><?= htmlspecialchars($activeSub['plan_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        до <strong><?= htmlspecialchars(date('d.m.Y', strtotime($activeSub['expires_at'])), ENT_QUOTES, 'UTF-8') ?></strong>.
        Управление — в <a href="/pages/cabinet.php">личном кабинете</a>.
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
            <li><span class="ic yes">✓</span><span><?= htmlspecialchars($featureRows['certs'], ENT_QUOTES, 'UTF-8') ?></span></li>
            <li><span class="ic yes">✓</span><span><?= htmlspecialchars($featureRows['portfolio'], ENT_QUOTES, 'UTF-8') ?></span></li>
            <li>
              <span class="ic yes">✓</span>
              <span><?= htmlspecialchars($featureRows['generator'], ENT_QUOTES, 'UTF-8') ?>:
                <strong><?= $unlimited ? 'безлимит' : ('≈ ' . max(1, (int)round($tokens / 15)) . ' материалов в месяц') ?></strong>
              </span>
            </li>
            <li>
              <span class="ic <?= $courseDisc > 0 ? 'yes' : 'no' ?>"><?= $courseDisc > 0 ? '✓' : '—' ?></span>
              <span class="<?= $courseDisc > 0 ? '' : 'muted' ?>">
                <?= htmlspecialchars($featureRows['courses'], ENT_QUOTES, 'UTF-8') ?>:
                <?= $courseDisc > 0 ? ('скидка ' . $courseDisc . '%') : 'без скидки' ?>
              </span>
            </li>
            <li>
              <span class="ic muted">⏳</span>
              <span class="muted"><?= htmlspecialchars($featureRows['aibot'], ENT_QUOTES, 'UTF-8') ?> — скоро<?= $isPro ? '' : ' (в тарифе Про)' ?></span>
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

    <p class="sub-note">
      Оплата разовая на выбранный период (месяц или год). Автопродление можно подключить
      позже. Документы, оформленные в период действия подписки, остаются у вас навсегда.
      Подписка не отменяет возможность покупать отдельные мероприятия и пакеты токенов.
    </p>

    <input type="hidden" id="sub-csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" id="sub-logged" value="<?= $userId ? '1' : '0' ?>">
  </div>
</section>

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
            window.location.href = '/vhod?return=' + encodeURIComponent('/podpiska/');
            return;
        }
        var orig = btn.textContent;
        btn.disabled = true; btn.style.opacity = '0.6'; btn.textContent = 'Создаём платёж…';
        var fd = new FormData();
        fd.append('csrf', document.getElementById('sub-csrf').value);
        fd.append('plan_slug', btn.dataset.plan);
        fd.append('period', subPeriod);
        fetch('/ajax/create-subscription-payment.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res.success && res.confirmation_url) { window.location.href = res.confirmation_url; return; }
                if (res.code === 'unauthorized') { window.location.href = '/vhod?return=' + encodeURIComponent('/podpiska/'); return; }
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

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

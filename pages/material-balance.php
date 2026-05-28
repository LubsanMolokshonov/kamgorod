<?php
/**
 * Личный баланс токенов для генератора материалов ФОП — /material-balance/
 *
 * Показывает текущий баланс, кнопки покупки пакетов (создаёт Yookassa payment
 * через ajax/buy-tokens.php) и историю транзакций (последние 50).
 *
 * GET ?paid=1 — флаг успешного возврата от Yookassa, показывает баннер
 * «Платёж принят, баланс обновится в течение минуты» (webhook асинхронный).
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/TokenPackage.php';
require_once __DIR__ . '/../includes/session.php';

$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    header('Location: /vhod?return=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$tokens = new UserTokens($db);
$packages = (new TokenPackage($db))->getActive();

// Идемпотентный стартовый бонус — на случай если пользователь до этого не заходил в генератор
$tokens->grantSignupBonusIfNeeded((int)$userId);

$record = $tokens->getRecord((int)$userId);
$history = $tokens->getHistory((int)$userId, 50);

$justPaid = !empty($_GET['paid']);
$csrf = generateCSRFToken();

$reasonLabels = [
    'signup_bonus' => 'Стартовый бонус',
    'purchase'     => 'Покупка пакета',
    'generation'   => 'Генерация материала',
    'adaptation'   => 'Адаптация материала',
    'download'     => 'Скачивание материала',
    'refund'       => 'Возврат (ошибка генерации)',
    'admin_grant'  => 'Начисление администратором',
    'admin_deduct' => 'Списание администратором',
];

$pageTitle = 'Мои токены — генератор материалов ФОП | ' . SITE_NAME;
$pageDescription = 'Баланс токенов для генерации материалов и история транзакций.';
$noindex = true;
$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/materialy/">Материалы ФОП</a>
      <span class="sep">/</span>
      <strong>Мои токены</strong>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:18px;">Мои токены</h1>
  </div>
</section>

<section class="mat-page">
  <div class="rd-wrap" style="max-width:920px;">
    <?php if ($justPaid): ?>
      <div class="mat-notice" style="background:#e7f5e7;border-color:#a7d8a7;">
        <strong>Платёж принят.</strong> Токены зачислятся на счёт в течение минуты — обновите страницу.
      </div>
    <?php endif; ?>

    <div class="mat-balance-hero">
      <div>
        <div class="bh-label">Текущий баланс</div>
        <div class="bh-num"><?= number_format((int)$record['balance'], 0, '', ' ') ?></div>
        <div class="bh-sub">
          Получено за всё время: <?= number_format((int)$record['lifetime_earned'], 0, '', ' ') ?> ·
          Потрачено: <?= number_format((int)$record['lifetime_spent'], 0, '', ' ') ?>
        </div>
      </div>
      <a href="/material-generator/" class="rd-btn rd-btn-primary" style="background:#fff;color:var(--indigo-700,#1a2f8a);">К генератору →</a>
    </div>

    <h2 class="rd-section-title" style="font-size:24px;margin-top:40px;">Пополнить баланс</h2>
    <div class="mat-packs" style="margin-top:16px;">
      <?php foreach ($packages as $i => $p):
          $totalTokens = (int)$p['tokens'] + (int)$p['bonus_tokens'];
          $perToken = $totalTokens > 0 ? (float)$p['price_rub'] / $totalTokens : 0;
          $featured = ($i === 1);
      ?>
        <div class="mat-pack<?= $featured ? ' mat-pack-featured' : '' ?>">
          <?php if ($featured): ?><div class="mat-pack-tag">Выгодно</div><?php endif; ?>
          <h3><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></h3>
          <div class="mat-pack-tokens"><?= number_format($totalTokens, 0, '', ' ') ?> <span>токенов</span></div>
          <?php if ((int)$p['bonus_tokens'] > 0): ?>
            <div class="mat-pack-bonus">+<?= (int)$p['bonus_tokens'] ?> бонусных</div>
          <?php endif; ?>
          <?php if (!empty($p['description'])): ?>
            <p class="mat-pack-desc"><?= htmlspecialchars($p['description'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
          <div class="mat-pack-price"><?= number_format((float)$p['price_rub'], 0, '', ' ') ?> ₽</div>
          <div class="mat-pack-per">~ <?= number_format($perToken, 2, ',', '') ?> ₽ за токен</div>
          <button type="button" class="buy-tokens-btn rd-btn <?= $featured ? 'rd-btn-primary' : 'rd-btn-ghost' ?>" data-package-id="<?= (int)$p['id'] ?>" style="width:100%;justify-content:center;">Купить</button>
        </div>
      <?php endforeach; ?>
    </div>

    <h2 class="rd-section-title" style="font-size:24px;margin-top:40px;">История транзакций</h2>
    <?php if (empty($history)): ?>
      <p style="color:var(--ink-400,#8b90a8);">Пока пусто. Первые транзакции появятся после генерации материалов.</p>
    <?php else: ?>
      <table class="mat-table">
        <thead>
          <tr><th>Дата</th><th>Операция</th><th style="text-align:right;">Сумма</th></tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><?= htmlspecialchars(date('d.m.Y H:i', strtotime($h['created_at'])), ENT_QUOTES, 'UTF-8') ?></td>
              <td>
                <?= htmlspecialchars($reasonLabels[$h['reason']] ?? $h['reason'], ENT_QUOTES, 'UTF-8') ?>
                <?php if (!empty($h['notes'])): ?>
                  <div style="font-size:11px;color:var(--ink-400,#8b90a8);"><?= htmlspecialchars($h['notes'], ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
              </td>
              <td class="<?= (int)$h['delta'] >= 0 ? 'delta-plus' : 'delta-minus' ?>">
                <?= (int)$h['delta'] >= 0 ? '+' : '' ?><?= number_format((int)$h['delta'], 0, '', ' ') ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>

    <input type="hidden" id="csrf-token" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

    <script>
    document.querySelectorAll('.buy-tokens-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var packageId = btn.dataset.packageId;
            var csrf = document.getElementById('csrf-token').value;
            btn.disabled = true;
            btn.style.opacity = '0.5';
            btn.textContent = 'Создаём платёж…';

            var fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('package_id', packageId);

            fetch('/ajax/buy-tokens.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res.success && res.confirmation_url) {
                        window.location.href = res.confirmation_url;
                        return;
                    }
                    alert(res.error || 'Не удалось создать платёж');
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.textContent = 'Купить';
                })
                .catch(function () {
                    alert('Сеть прервалась');
                    btn.disabled = false;
                    btn.style.opacity = '1';
                    btn.textContent = 'Купить';
                });
        });
    });
    </script>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

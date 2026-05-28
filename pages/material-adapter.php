<?php
/**
 * Адаптация чужого материала через ИИ — /material-adapter/
 *
 * Учитель вставляет свой материал + инструкцию, получает переписанную версию
 * прямо на странице (без сохранения в каталог). Стоимость фиксированная.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../classes/MaterialAdapter.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/material-tracking.php';

trackMaterialVisit($db, '/material-adapter/');

$userId = $_SESSION['user_id'] ?? null;
$balance = null;
if ($userId) {
    $tokens = new UserTokens($db);
    $tokens->grantSignupBonusIfNeeded((int)$userId);
    $balance = $tokens->getBalance((int)$userId);
}
$cost = MaterialAdapter::tokenCost();
$csrf = generateCSRFToken();

$pageTitle = 'Адаптация материала под ОВЗ, ФОП, класс — ИИ-помощник | ' . SITE_NAME;
$pageDescription = 'Вставьте конспект, рабочую программу или техкарту и попросите ИИ адаптировать под ОВЗ, ФАОП, другой класс или ФОП 2026.';
$canonicalUrl = SITE_URL . '/material-adapter/';
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
      <strong>Адаптация</strong>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:18px;">Адаптировать материал</h1>
    <p class="rd-hero-sub" style="max-width:680px;">Вставьте свой материал и опишите, как его нужно адаптировать — под ОВЗ, ФАОП, другую возрастную группу, требования ФОП-2026 или просто короче. Получите переписанную версию за 15–30 секунд.</p>
  </div>
</section>

<section class="mat-page">
  <div class="rd-wrap mat-form-wrap">
    <?php if (!$userId): ?>
      <div class="mat-notice">
        <a href="/vhod?return=<?= urlencode('/material-adapter/') ?>">Войдите</a>
        или зарегистрируйтесь — <?= UserTokens::signupBonus() ?> токенов в подарок.
      </div>
    <?php else: ?>
      <div class="mat-balance-pill">
        Баланс: <strong><?= number_format((int)$balance, 0, '', ' ') ?> токенов</strong> · Стоимость адаптации: <strong><?= $cost ?> токенов</strong>
        <?php if ($balance < $cost): ?> · <a href="/material-balance/">пополнить</a><?php endif; ?>
      </div>

      <form id="adapter-form" onsubmit="return false;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">

        <div class="mat-field">
          <label>Исходный материал <span class="req">*</span></label>
          <textarea name="source_text" rows="10" required
                    placeholder="Вставьте конспект урока, фрагмент рабочей программы, техкарту или другой текст до 20 000 символов"></textarea>
        </div>

        <div class="mat-field">
          <label>Как адаптировать <span class="req">*</span></label>
          <textarea name="instructions" rows="3" required
                    placeholder="Например: «адаптируй под 2 класс с лёгкой формой ЗПР, упрости задания» или «переделай под ФОП-2026, добавь УУД»"></textarea>
        </div>

        <button type="submit" id="adapter-submit" class="rd-btn rd-btn-primary">Адаптировать за <?= $cost ?> токенов</button>
      </form>

      <div id="adapter-status" class="mat-status">
        <div class="mat-loader"></div>
        <p>ИИ переписывает… Обычно 15–30 секунд.</p>
      </div>

      <div id="adapter-error" class="mat-error"></div>

      <div id="adapter-result" class="mat-result">
        <h2>Результат</h2>
        <div id="adapter-result-text" class="mat-result-text"></div>
        <button type="button" id="adapter-copy" class="rd-btn rd-btn-ghost" style="margin-top:12px;">Скопировать</button>
      </div>

      <script>
      (function () {
          var form = document.getElementById('adapter-form');
          var statusEl = document.getElementById('adapter-status');
          var errorEl = document.getElementById('adapter-error');
          var resultEl = document.getElementById('adapter-result');
          var resultTextEl = document.getElementById('adapter-result-text');
          var submitBtn = document.getElementById('adapter-submit');
          var copyBtn = document.getElementById('adapter-copy');

          form.addEventListener('submit', function () {
              errorEl.style.display = 'none';
              resultEl.style.display = 'none';
              statusEl.style.display = 'block';
              submitBtn.disabled = true;
              submitBtn.style.opacity = '0.5';

              var fd = new FormData(form);
              fetch('/ajax/adapt-material.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (res) {
                      statusEl.style.display = 'none';
                      submitBtn.disabled = false;
                      submitBtn.style.opacity = '1';
                      if (res.success) {
                          resultTextEl.textContent = res.result_text;
                          resultEl.style.display = 'block';
                          resultEl.scrollIntoView({ behavior: 'smooth' });
                          return;
                      }
                      var msg = res.error || 'Ошибка';
                      if (res.code === 'not_enough_tokens' && res.buy_url) {
                          msg += ' <a href="' + res.buy_url + '">Пополнить →</a>';
                      }
                      errorEl.innerHTML = msg;
                      errorEl.style.display = 'block';
                  })
                  .catch(function () {
                      statusEl.style.display = 'none';
                      submitBtn.disabled = false;
                      submitBtn.style.opacity = '1';
                      errorEl.textContent = 'Сеть прервалась. Попробуйте ещё раз.';
                      errorEl.style.display = 'block';
                  });
          });

          copyBtn.addEventListener('click', function () {
              navigator.clipboard.writeText(resultTextEl.textContent).then(function () {
                  var old = copyBtn.textContent;
                  copyBtn.textContent = 'Скопировано ✓';
                  setTimeout(function () { copyBtn.textContent = old; }, 1500);
              });
          });
      })();
      </script>
    <?php endif; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

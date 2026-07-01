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
    require_once __DIR__ . '/../classes/SubscriptionService.php';
    (new SubscriptionService($db))->grantMonthlyTokensIfDue((int)$userId);
    $balance = $tokens->getBalance((int)$userId);
}
$cost = MaterialAdapter::tokenCost();
$csrf = generateCSRFToken();

$pageTitle = 'Адаптация материала под ОВЗ, ФОП, класс — ИИ-помощник | ' . SITE_NAME;
$pageDescription = 'Вставьте конспект, рабочую программу или техкарту и попросите ИИ адаптировать под ОВЗ, ФАОП, другой класс или ФОП 2026.';
$canonicalUrl = SITE_URL . '/material-adapter/';
$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

// FAQ + микроразметка Schema.org/FAQPage (виден всем, в т.ч. поисковому роботу)
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Сколько стоит адаптация материала?', 'a' => 'Одна адаптация стоит ' . $cost . ' токенов. Новым пользователям после регистрации начисляется ' . UserTokens::signupBonus() . ' токенов в подарок — первую адаптацию можно сделать бесплатно.'],
    ['q' => 'Мой текст где-то сохраняется или публикуется?', 'a' => 'Нет. Результат адаптации показывается только вам на странице и не публикуется в каталоге материалов. Ваш исходный текст мы не размещаем в открытом доступе.'],
    ['q' => 'Какой объём текста можно вставить?', 'a' => 'До 20 000 символов за один раз — это примерно конспект урока, фрагмент рабочей программы или техкарта. Большой документ адаптируйте по частям.'],
    ['q' => 'Чем адаптация отличается от генератора материалов?', 'a' => 'Генератор создаёт новый материал с нуля по вашему заданию, а адаптер переписывает ваш готовый текст под новые требования — другой класс, ОВЗ, ФАОП или ФОП-2026. Если материала ещё нет — воспользуйтесь <a href="/material-generator/">генератором</a>.'],
];
$jsonLdArray = [buildFaqJsonLd($faqItems)];

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

    <!-- Как это работает (видно всем) -->
    <div class="mat-how">
      <h2>Как работает адаптация материала</h2>
      <ol class="mat-how-steps">
        <li><strong>Вставьте свой материал.</strong> Конспект урока, фрагмент рабочей программы, технологическую карту или любой другой текст до 20 000 символов.</li>
        <li><strong>Опишите, как адаптировать.</strong> Например: «упрости под 2 класс с лёгкой формой ЗПР» или «переделай под ФОП-2026, добавь УУД».</li>
        <li><strong>Получите переписанную версию.</strong> ИИ адаптирует текст за 15–30 секунд — останется скопировать результат и использовать на уроке.</li>
      </ol>
    </div>

    <!-- Сценарии использования (видно всем) -->
    <div class="mat-usecases">
      <h2>Когда пригодится адаптация</h2>
      <p>Инструмент помогает быстро переработать уже готовый материал под конкретную задачу, не переписывая его вручную:</p>
      <ul>
        <li><strong>Дети с ОВЗ и ФАОП.</strong> Упростить формулировки, снизить нагрузку, адаптировать задания под особенности обучающихся с ЗПР, ТНР, РАС и другими нарушениями.</li>
        <li><strong>Другой класс или возраст.</strong> Перенести материал с одной параллели на другую, изменив сложность и объём.</li>
        <li><strong>Переход на ФОП-2026 и обновлённый ФГОС.</strong> Привести конспект или программу в соответствие с новыми требованиями, добавить формируемые УУД и планируемые результаты.</li>
        <li><strong>Сокращение и упрощение.</strong> Сделать материал короче, понятнее и удобнее для восприятия.</li>
      </ul>
      <p>Не нашли готового материала в <a href="/materialy/katalog/">каталоге</a>? Создайте свой с нуля через <a href="/material-generator/">ИИ-генератор</a>, а затем при необходимости адаптируйте его здесь.</p>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="mat-page" style="padding-top:0;">
  <div class="rd-wrap" style="max-width:880px;">
    <h2 class="mat-faq-title">Частые вопросы об адаптации</h2>
    <?php renderFaqList($faqItems); ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

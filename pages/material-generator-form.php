<?php
/**
 * Форма генерации материала — /material-generator/{type-slug}/
 * Заполняем поля → POST /ajax/generate-material.php → ждём 15-60 сек → редирект на /material/{slug}/.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/material-tracking.php';

$typeObj = new MaterialType($db);
$typeSlug = $_GET['type_slug'] ?? '';

trackMaterialVisit($db, '/material-generator/' . ($typeSlug !== '' ? $typeSlug . '/' : ''));
$type = $typeSlug ? $typeObj->getBySlug($typeSlug) : null;

if (!$type) {
    http_response_code(404);
    $pageTitle = 'Тип материала не найден';
    $rdActivePage = 'materialy';
    include __DIR__ . '/../includes/header-redesign.php';
    echo '<div class="rd-wrap" style="padding:60px 20px; text-align:center;">'
        . '<h1>Тип материала не найден</h1>'
        . '<p><a href="/material-generator/" style="color:var(--indigo-600);">← К списку типов</a></p></div>';
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$userId = $_SESSION['user_id'] ?? null;
$balance = null;
if ($userId) {
    $tokens = new UserTokens($db);
    $tokens->grantSignupBonusIfNeeded((int)$userId);
    $balance = $tokens->getBalance((int)$userId);
}

$csrfToken = generateCSRFToken();
$cost = (int)$type['token_cost_default'];

// Реальный формат скачиваемого файла (для копий в интерфейсе вместо хардкода «PDF»)
$formatLabels = ['pdf' => 'PDF', 'docx' => 'Word (DOCX)', 'pptx' => 'PowerPoint (PPTX)'];
$outFormat = strtolower((string)($type['output_format'] ?? 'pdf'));
$formatLabel = $formatLabels[$outFormat] ?? strtoupper($outFormat);

// Преимущества под конкретный тип — релевантность лендинга рекламному объявлению
$typeBenefits = [
    'tehkarta-uroka'   => ['Структура строго по ФГОС: цели, УУД, этапы', 'Готовая таблица — копируйте в свой план', 'Учёт особенностей класса и ОВЗ'],
    'konspekt-uroka'   => ['Развёрнутый ход урока с репликами учителя', 'Этапы с хронометражем', 'Домашнее задание и оборудование'],
    'rabochiy-list'    => ['5–8 заданий разных типов', 'Ключи с ответами для проверки', 'Под возраст и тему вашего класса'],
    'test-kontrolnaya' => ['Вопросы с вариантами и пояснениями', 'Нужное вам количество вопросов', 'Готово к печати и выдаче'],
    'prezentatsiya'    => ['10–20 слайдов с заметками для учителя', 'Логика: титул → содержание → итоги', 'Под предмет, класс и тему'],
    'klassnyy-chas'    => ['Сценарий с целью и структурой', 'Вопросы для обсуждения и рефлексия', 'Под возраст и воспитательную задачу'],
    'ktp-fragment'     => ['Таблица КТП с УУД и контролем', 'Нужное количество часов', 'Соответствие программе (ФОП/ФГОС)'],
];
$benefits = $typeBenefits[$type['slug']] ?? [];

// Какие поля формы нужны — определяем по плейсхолдерам в шаблоне промпта
$template = (string)($type['ai_prompt_template'] ?? '');
preg_match_all('/\{([a-z_]+)\}/i', $template, $matches);
$placeholders = array_unique($matches[1]);
// {stage} вычисляется автоматически из класса/программы (MaterialType::deriveStage) —
// поле для ручного ввода не показываем.
$placeholders = array_values(array_filter($placeholders, fn($p) => $p !== 'stage'));

$fieldsConfig = [
    'subject'         => ['label' => 'Предмет / образовательная область', 'placeholder' => 'Например: Русский язык', 'required' => true],
    'class'           => ['label' => 'Класс / возрастная группа',         'placeholder' => 'Например: 3 класс или старшая группа', 'required' => true],
    'topic'           => ['label' => 'Тема',                              'placeholder' => 'Например: Имя прилагательное', 'required' => true],
    'duration'        => ['label' => 'Длительность (мин)',                'placeholder' => '45', 'type' => 'number', 'required' => false],
    'features'        => ['label' => 'Особенности группы',                'placeholder' => $type['slug'] === 'klassnyy-chas' ? 'Например: 24 человека, есть конфликты; тема острая — нужен психолог' : 'Например: группа с ОВЗ/ТНР, гиперактивный класс, 24 человека', 'type' => 'textarea', 'required' => false],
    'questions_count' => ['label' => 'Количество вопросов',               'placeholder' => '10', 'type' => 'number', 'required' => false],
    'slides_count'    => ['label' => 'Количество слайдов',                'placeholder' => '12', 'type' => 'number', 'required' => false],
    'hours'           => ['label' => 'Количество часов',                  'placeholder' => '4', 'type' => 'number', 'required' => false],
    'test_mode'       => [
        'label' => 'Критерии теста', 'type' => 'select', 'required' => false,
        'options' => [
            'один правильный ответ в каждом вопросе'        => 'Один правильный ответ',
            'допускаются вопросы с несколькими правильными ответами' => 'Возможны несколько правильных ответов',
            'смешанный: тест + открытые вопросы'            => 'Смешанный (тест + открытые вопросы)',
        ],
    ],
    'program'         => [
        'label' => 'Программа (для адресности по ФГОС/ФОП)', 'type' => 'select', 'required' => true,
        // data-stages: на каких ступенях уместна программа — для фильтрации по классу
        'options' => [
            ''           => ['label' => '— любая —',      'stages' => 'do,noo,ooo,soo'],
            'ФОП ДО'     => ['label' => 'ФОП ДО',         'stages' => 'do'],
            'ФОП НОО'    => ['label' => 'ФОП НОО',        'stages' => 'noo'],
            'ФОП ООО'    => ['label' => 'ФОП ООО',        'stages' => 'ooo'],
            'ФОП СОО'    => ['label' => 'ФОП СОО',        'stages' => 'soo'],
            'ФАОП (ОВЗ)' => ['label' => 'ФАОП (ОВЗ)',     'stages' => 'do,noo,ooo,soo'],
            'ФГОС 2021'  => ['label' => 'ФГОС 2021',      'stages' => 'noo,ooo,soo'],
            'ФГОС 2026'  => ['label' => 'ФГОС 2026',      'stages' => 'noo,ooo,soo'],
        ],
    ],
];

$pageTitle = $type['name'] . ' — ИИ-генератор | ' . SITE_NAME;
$pageDescription = 'Сгенерируйте ' . mb_strtolower($type['name']) . ' через ИИ за 30 секунд. ' . ($type['description'] ?? '');
$canonicalUrl = SITE_URL . '/material-generator/' . rawurlencode($type['slug']) . '/';
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
      <a href="/material-generator/">Генератор</a>
      <span class="sep">/</span>
      <strong><?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:18px;"><?= htmlspecialchars($type['name'], ENT_QUOTES, 'UTF-8') ?></h1>
    <p class="rd-hero-sub" style="max-width:640px;"><?= htmlspecialchars($type['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
    <?php if (!empty($benefits)): ?>
      <ul class="mat-hero-benefits" style="list-style:none;padding:0;margin:16px 0 0;display:flex;flex-wrap:wrap;gap:8px 20px;max-width:720px;">
        <?php foreach ($benefits as $b): ?>
          <li style="position:relative;padding-left:24px;font-size:15px;">
            <span style="position:absolute;left:0;color:#16a34a;">✓</span><?= htmlspecialchars($b, ENT_QUOTES, 'UTF-8') ?>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</section>

<?php
$bonus = UserTokens::signupBonus();
// Пресеты-примеры для быстрого заполнения (заполняются только существующие поля формы)
$presets = [
    ['label' => '🧮 Математика, 3 класс', 'subject' => 'Математика', 'class' => '3 класс', 'topic' => 'Умножение и деление'],
    ['label' => '📖 Русский язык, 5 класс', 'subject' => 'Русский язык', 'class' => '5 класс', 'topic' => 'Имя прилагательное'],
    ['label' => '🌍 Окружающий мир, 2 класс', 'subject' => 'Окружающий мир', 'class' => '2 класс', 'topic' => 'Времена года'],
];
?>
<section class="mat-page">
  <div class="rd-wrap mat-form-wrap">
      <div class="mat-notice">
        <strong>Генерация бесплатна.</strong> Заполните поля → получите готовый материал.
        Скачивание чистого файла (<?= htmlspecialchars($formatLabel, ENT_QUOTES, 'UTF-8') ?>) — <strong><?= $cost ?> токенов</strong>.
        <?php if ($userId): ?>
          Ваш баланс: <strong><?= number_format((int)$balance, 0, '', ' ') ?></strong> токенов.
        <?php else: ?>
          Первый материал бесплатно — дарим <strong><?= $bonus ?> токенов</strong> при регистрации.
        <?php endif; ?>
      </div>

      <div class="mat-presets" style="display:flex;flex-wrap:wrap;gap:8px;margin:14px 0;">
        <span style="color:var(--ink-400,#8b90a8);font-size:14px;align-self:center;">Примеры:</span>
        <?php foreach ($presets as $i => $p): ?>
          <button type="button" class="rd-btn rd-btn-ghost mat-preset" data-preset="<?= $i ?>" style="font-size:14px;padding:6px 12px;"><?= htmlspecialchars($p['label'], ENT_QUOTES, 'UTF-8') ?></button>
        <?php endforeach; ?>
      </div>

      <form id="generator-form" onsubmit="return false;">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="type_slug" value="<?= htmlspecialchars($type['slug'], ENT_QUOTES, 'UTF-8') ?>">

        <?php foreach ($placeholders as $name): ?>
          <?php $cfg = $fieldsConfig[$name] ?? ['label' => $name, 'placeholder' => '', 'required' => false]; ?>
          <div class="mat-field">
            <label>
              <?= htmlspecialchars($cfg['label'], ENT_QUOTES, 'UTF-8') ?><?= !empty($cfg['required']) ? ' <span class="req">*</span>' : '' ?>
            </label>
            <?php if (($cfg['type'] ?? '') === 'textarea'): ?>
              <textarea name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                        placeholder="<?= htmlspecialchars($cfg['placeholder'], ENT_QUOTES, 'UTF-8') ?>"
                        rows="3" <?= !empty($cfg['required']) ? 'required' : '' ?>></textarea>
            <?php elseif (($cfg['type'] ?? '') === 'select'): ?>
              <select name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"<?= $name === 'program' ? ' data-program-select' : '' ?><?= !empty($cfg['required']) ? ' required' : '' ?>>
                <?php foreach (($cfg['options'] ?? []) as $val => $opt): ?>
                  <?php
                    // Опция может быть строкой (label) или массивом ['label'=>..,'stages'=>..]
                    $optLabel  = is_array($opt) ? ($opt['label'] ?? $val) : $opt;
                    $optStages = is_array($opt) ? ($opt['stages'] ?? '') : '';
                  ?>
                  <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"<?= $optStages !== '' ? ' data-stages="' . htmlspecialchars($optStages, ENT_QUOTES, 'UTF-8') . '"' : '' ?>><?= htmlspecialchars($optLabel, ENT_QUOTES, 'UTF-8') ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?>
              <input type="<?= htmlspecialchars($cfg['type'] ?? 'text', ENT_QUOTES, 'UTF-8') ?>"
                     name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>"
                     placeholder="<?= htmlspecialchars($cfg['placeholder'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                     <?= !empty($cfg['required']) ? 'required' : '' ?>>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

        <button type="submit" id="generator-submit" class="rd-btn rd-btn-primary">
          Сгенерировать бесплатно
        </button>
      </form>

      <div id="generator-status" class="mat-status">
        <div class="mat-loader"></div>
        <p id="gen-status-text">ИИ работает… Обычно 30–90 секунд, иногда до 3 минут.</p>
        <p style="color:var(--ink-400,#8b90a8); font-size:14px;">Можно не закрывать вкладку — если закроете, генерация продолжится в фоне. Статус и готовый материал всегда видны в <a href="/kabinet/?tab=materials" style="color:var(--indigo-600,#4f46e5);">личном кабинете → «Материалы ФОП»</a>.</p>
      </div>

      <div id="generator-error" class="mat-error"></div>

      <script>
      (function () {
          var form = document.getElementById('generator-form');
          var statusEl = document.getElementById('generator-status');
          var errorEl = document.getElementById('generator-error');
          var submitBtn = document.getElementById('generator-submit');
          var presets = <?= json_encode($presets, JSON_UNESCAPED_UNICODE) ?>;
          var IS_LOGGED_IN = <?= $userId ? 'true' : 'false' ?>;
          var CSRF_TOKEN = <?= json_encode($csrfToken, JSON_UNESCAPED_UNICODE) ?>;

          // Фильтр программ по выбранному классу: для 5 класса не показываем ФОП ДО/НОО и т.п.
          var classInput = form.querySelector('[name="class"]');
          var programSelect = form.querySelector('[data-program-select]');
          function stageFromClass(text) {
              if (!text) return null;
              var t = text.toLowerCase();
              if (/(детс|дошкол|сад|младш|средн|старш|подготов|ясел|групп)/.test(t)) return 'do';
              var m = t.match(/(\d+)/);
              if (!m) return null;
              var n = parseInt(m[1], 10);
              if (n >= 1 && n <= 4) return 'noo';
              if (n >= 5 && n <= 9) return 'ooo';
              if (n >= 10 && n <= 11) return 'soo';
              return null;
          }
          function filterPrograms() {
              if (!programSelect || !classInput) return;
              var stage = stageFromClass(classInput.value);
              Array.prototype.forEach.call(programSelect.options, function (opt) {
                  var stages = opt.getAttribute('data-stages');
                  // Без stage-метки или без выбранного класса — показываем всё.
                  var show = !stage || !stages || stages.split(',').indexOf(stage) !== -1;
                  opt.hidden = !show;
                  opt.disabled = !show;
              });
              // Если текущий выбор скрылся — сбрасываем на «— любая —»
              if (programSelect.selectedOptions[0] && programSelect.selectedOptions[0].hidden) {
                  programSelect.value = '';
              }
          }
          if (classInput && programSelect) {
              classInput.addEventListener('input', filterPrograms);
              filterPrograms();
          }

          // Пресеты — заполняют существующие поля формы
          document.querySelectorAll('.mat-preset').forEach(function (btn) {
              btn.addEventListener('click', function () {
                  var p = presets[+btn.getAttribute('data-preset')];
                  ['subject', 'class', 'topic'].forEach(function (f) {
                      var el = form.querySelector('[name="' + f + '"]');
                      if (el && p[f]) el.value = p[f];
                  });
                  filterPrograms();
              });
          });

          var pollTimer = null;
          var statusTextEl = document.getElementById('gen-status-text');

          function resetUi() {
              statusEl.style.display = 'none';
              submitBtn.disabled = false;
              submitBtn.style.opacity = '1';
          }
          function showError(html) {
              if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
              resetUi();
              errorEl.innerHTML = html;
              errorEl.style.display = 'block';
          }

          // Кнопка «Увеличить лимит»: поднимает персональный суточный лимит и сразу
          // повторяет генерацию. Доступна только залогиненным.
          function bindIncreaseLimit() {
              var link = document.getElementById('increase-limit-link');
              if (!link) return;
              link.addEventListener('click', function (e) {
                  e.preventDefault();
                  link.textContent = 'Увеличиваем лимит…';
                  var fd = new FormData();
                  fd.append('csrf', CSRF_TOKEN);
                  fetch('/ajax/increase-material-limit.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                      .then(function (r) { return r.json(); })
                      .then(function (d) {
                          if (d && d.success) {
                              errorEl.style.display = 'none';
                              runGeneration();
                          } else {
                              showError((d && d.error) || 'Не удалось увеличить лимит, попробуйте позже.');
                          }
                      })
                      .catch(function () {
                          showError('Не удалось увеличить лимит. Проверьте интернет и попробуйте ещё раз.');
                      });
              });
          }

          // Генерация асинхронна: POST мгновенно ставит задачу в очередь и возвращает
          // status_url; дальше опрашиваем статус, пока воркер не закончит. Так запрос
          // не висит и не упирается в таймаут прокси.
          function runGeneration() {
              errorEl.style.display = 'none';
              errorEl.textContent = '';
              statusEl.style.display = 'block';
              if (statusTextEl) statusTextEl.textContent = 'Отправляем запрос…';
              submitBtn.disabled = true;
              submitBtn.style.opacity = '0.5';

              var fd = new FormData(form);
              // POST теперь короткий — короткий таймаут на саму постановку в очередь.
              var ctrl = new AbortController();
              var postTimeout = setTimeout(function () { ctrl.abort(); }, 20000);

              fetch('/ajax/generate-material.php', { method: 'POST', body: fd, credentials: 'same-origin', signal: ctrl.signal })
                  .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                  .then(function (res) {
                      clearTimeout(postTimeout);
                      var d = res.data || {};
                      if (d.success && d.status_url) {
                          startPolling(d.status_url);
                          return;
                      }
                      var msg = d.error || 'Ошибка генерации';
                      if (d.code === 'rate_limited') {
                          // Залогиненному не предлагаем регистрацию — даём поднять лимит.
                          // Анониму — приглашение зарегистрироваться (бонусные токены, выше лимит).
                          if (IS_LOGGED_IN) {
                              showError(msg + ' <a href="#" id="increase-limit-link">Увеличить лимит →</a>');
                              bindIncreaseLimit();
                              return;
                          }
                          msg += ' <a href="/vhod?return=' + encodeURIComponent(location.pathname) + '">Зарегистрироваться →</a>';
                      }
                      showError(msg);
                  })
                  .catch(function () {
                      clearTimeout(postTimeout);
                      showError('Не удалось отправить запрос. Проверьте интернет и попробуйте ещё раз.');
                  });
          }

          // Ориентир обратного отсчёта: типичная генерация укладывается в это время.
          // Если воркер не успел — таймер замирает на 0:00 с пометкой «почти готово».
          var COUNTDOWN_TARGET = 120; // сек

          function fmtMMSS(sec) {
              if (sec < 0) sec = 0;
              var m = Math.floor(sec / 60);
              var s = sec % 60;
              return m + ':' + (s < 10 ? '0' + s : s);
          }

          function startPolling(statusUrl) {
              var startedAt = Date.now();
              pollTimer = setInterval(function () {
                  var elapsed = Math.round((Date.now() - startedAt) / 1000);
                  // Предохранитель: 5 минут. Материал всё равно допишется воркером.
                  if (elapsed > 300) {
                      clearInterval(pollTimer); pollTimer = null;
                      showError('Генерация затянулась, но продолжается в фоне. Материал появится в <a href="/kabinet/?tab=materials">личном кабинете → «Материалы ФОП»</a> через пару минут — там же будет видно, если произошла ошибка.');
                      return;
                  }
                  if (statusTextEl) {
                      var remaining = COUNTDOWN_TARGET - elapsed;
                      statusTextEl.textContent = (remaining > 0)
                          ? 'ИИ готовит материал по методике… осталось ~' + fmtMMSS(remaining)
                          : 'Почти готово, дорабатываем последние детали…';
                  }
                  fetch(statusUrl, { credentials: 'same-origin' })
                      .then(function (r) { return r.json(); })
                      .then(function (d) {
                          if (!d || !d.success) { return; } // мягко продолжаем опрос
                          if (d.status === 'done') {
                              clearInterval(pollTimer); pollTimer = null;
                              // Единая цель-конверсия для рекламы: «реклама → генерация».
                              if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'material_preview'); }
                              window.location.href = d.redirect_url;
                          } else if (d.status === 'failed') {
                              clearInterval(pollTimer); pollTimer = null;
                              showError((d.error || 'Не удалось сгенерировать материал.') + ' Токены за неудачную генерацию возвращены. Попробуйте ещё раз — статус всегда виден в <a href="/kabinet/?tab=materials">личном кабинете</a>.');
                          }
                          // pending/running — ждём следующего тика
                      })
                      .catch(function () { /* единичный сбой опроса — не падаем, ждём следующего тика */ });
              }, 2500);
          }

          // Превью-генерация бесплатна и не требует регистрации — оплата на скачивании.
          form.addEventListener('submit', function () {
              if (!form.checkValidity()) { form.reportValidity(); return; }
              runGeneration();
          });
      })();
      </script>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

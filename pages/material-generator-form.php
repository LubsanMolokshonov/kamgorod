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

$fieldsConfig = [
    'subject'         => ['label' => 'Предмет / образовательная область', 'placeholder' => 'Например: Русский язык', 'required' => true],
    'class'           => ['label' => 'Класс / возрастная группа',         'placeholder' => 'Например: 3 класс или старшая группа', 'required' => true],
    'topic'           => ['label' => 'Тема',                              'placeholder' => 'Например: Имя прилагательное', 'required' => true],
    'duration'        => ['label' => 'Длительность (мин)',                'placeholder' => '45', 'type' => 'number', 'required' => false],
    'features'        => ['label' => 'Особенности группы',                'placeholder' => 'Например: группа с ОВЗ, есть ребёнок с ЗПР', 'type' => 'textarea', 'required' => false],
    'questions_count' => ['label' => 'Количество вопросов',               'placeholder' => '10', 'type' => 'number', 'required' => false],
    'slides_count'    => ['label' => 'Количество слайдов',                'placeholder' => '12', 'type' => 'number', 'required' => false],
    'hours'           => ['label' => 'Количество часов',                  'placeholder' => '4', 'type' => 'number', 'required' => false],
    'program'         => [
        'label' => 'Программа', 'type' => 'select', 'required' => false,
        'options' => [
            '' => '— любая —', 'ФОП ДО' => 'ФОП ДО', 'ФОП НОО' => 'ФОП НОО',
            'ФОП ООО' => 'ФОП ООО', 'ФОП СОО' => 'ФОП СОО', 'ФАОП (ОВЗ)' => 'ФАОП (ОВЗ)',
            'ФГОС 2021' => 'ФГОС 2021', 'ФГОС 2026' => 'ФГОС 2026',
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
        Скачивание чистого файла (PDF) — <strong><?= $cost ?> токенов</strong>.
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
              <select name="<?= htmlspecialchars($name, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach (($cfg['options'] ?? []) as $val => $label): ?>
                  <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></option>
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
        <p>ИИ работает… Обычно занимает 15–40 секунд.</p>
        <p style="color:var(--ink-400,#8b90a8); font-size:14px;">Не закрывайте вкладку.</p>
      </div>

      <div id="generator-error" class="mat-error"></div>

      <script>
      (function () {
          var form = document.getElementById('generator-form');
          var statusEl = document.getElementById('generator-status');
          var errorEl = document.getElementById('generator-error');
          var submitBtn = document.getElementById('generator-submit');
          var presets = <?= json_encode($presets, JSON_UNESCAPED_UNICODE) ?>;

          // Пресеты — заполняют существующие поля формы
          document.querySelectorAll('.mat-preset').forEach(function (btn) {
              btn.addEventListener('click', function () {
                  var p = presets[+btn.getAttribute('data-preset')];
                  ['subject', 'class', 'topic'].forEach(function (f) {
                      var el = form.querySelector('[name="' + f + '"]');
                      if (el && p[f]) el.value = p[f];
                  });
              });
          });

          function runGeneration() {
              errorEl.style.display = 'none';
              errorEl.textContent = '';
              statusEl.style.display = 'block';
              submitBtn.disabled = true;
              submitBtn.style.opacity = '0.5';

              var fd = new FormData(form);
              fetch('/ajax/generate-material.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, data: d }; }); })
                  .then(function (res) {
                      if (res.data && res.data.success) {
                          // Единая цель-конверсия для рекламы: «реклама → генерация» (совпадает с material_generations в БД)
                          if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'material_preview'); }
                          window.location.href = res.data.redirect_url;
                          return;
                      }
                      statusEl.style.display = 'none';
                      submitBtn.disabled = false;
                      submitBtn.style.opacity = '1';
                      var msg = (res.data && res.data.error) ? res.data.error : 'Ошибка генерации';
                      if (res.data && res.data.code === 'rate_limited' && !res.data.success) {
                          msg += ' <a href="/vhod?return=' + encodeURIComponent(location.pathname) + '">Зарегистрироваться →</a>';
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

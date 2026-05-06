<?php
/**
 * Olympiad Catalog Page — редизайн (стиль конкурсов)
 * 3-уровневая фильтрация: ac (категория) / at (тип) / as (специализация)
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Olympiad.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/seo-url.php';

$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

redirectToSeoUrl('olimpiady', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

$audienceCatObj  = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('olympiad');

$selectedCategoryData    = null;
$audienceTypes           = [];
$selectedTypeData        = null;
$audienceSpecializations = [];

if ($selectedCategory) {
    $selectedCategoryData = $audienceCatObj->getBySlug($selectedCategory);
    if ($selectedCategoryData) {
        $audienceTypes = $audienceCatObj->getAudienceTypes($selectedCategoryData['id']);
    }
}
if ($selectedType) {
    $selectedTypeData = $audienceTypeObj->getBySlug($selectedType);
    if ($selectedTypeData) {
        $audienceSpecializations = $audienceTypeObj->getSpecializations($selectedTypeData['id']);
    }
}

$olympiadObj = new Olympiad($db);
$filters = [];
if ($selectedCategoryData) $filters['category_id'] = $selectedCategoryData['id'];
if ($selectedTypeData)     $filters['audience_type_id'] = $selectedTypeData['id'];
if (!empty($selectedSpec)) {
    require_once __DIR__ . '/classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
    if ($selectedSpecData) {
        $filters['specialization_id'] = $selectedSpecData['id'];
    }
}

$allOlympiads = !empty($filters)
    ? $olympiadObj->getFilteredOlympiads($filters)
    : $olympiadObj->getActiveOlympiads();

$perPage           = 21;
$totalOlympiads    = count($allOlympiads);
$olympiads         = array_slice($allOlympiads, 0, $perPage);
$hasMore           = $totalOlympiads > $perPage;

// Лёгкий массив для клиентского поиска
$allOlympiadsJs = [];
foreach ($allOlympiads as $o) {
    $audienceLabel = Olympiad::getAudienceLabel($o['target_audience'] ?? '');
    $allOlympiadsJs[] = [
        'id'             => $o['id'],
        'title'          => $o['title'],
        'description'    => $o['description'] ?? '',
        'audience_label' => $audienceLabel,
        'subject'        => $o['subject'] ?? '',
        'price'          => (float)($o['diploma_price'] ?? 169),
        'url'            => '/olimpiady/' . urlencode($o['slug']) . '/',
    ];
}

$pageTitle       = 'Олимпиады для педагогов и учеников 2025-2026 | ' . SITE_NAME;
$pageDescription = 'Всероссийские бесплатные олимпиады для педагогов и школьников. Пройдите тест и получите официальный диплом за 30 секунд.';
$canonicalUrl    = SITE_URL . '/olimpiady/';
$ogImage         = SITE_URL . '/assets/images/og-olympiads.jpg';
$rdActivePage    = 'olimpiady';
$additionalCSS   = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/assets/css/competition-detail.css'),
    '/assets/css/olympiad-detail.css?v=' . filemtime(__DIR__ . '/assets/css/olympiad-detail.css'),
];
$earlyHeadScripts = ['<script>' . file_get_contents(__DIR__ . '/assets/js/catalog-scroll.js') . '</script>'];

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Олимпиады</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill free"><span class="dot"></span>Бесплатное участие</span>
        <span class="rd-pill"><?php echo $totalOlympiads; ?>+ активных олимпиад</span>
        <span class="rd-pill indigo">Соответствует ФГОС</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal">Олимпиады для педагогов и&nbsp;учеников с&nbsp;<span class="accent">дипломом за&nbsp;30&nbsp;секунд</span></h1>
      <p class="rd-hero-sub reveal">Проверьте знания, получите результат сразу и оформите официальный диплом для портфолио и аттестации. Тест бесплатный — оплата только за оформление диплома.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Тест бесплатно · 10 вопросов</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Результат сразу после теста</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Подходит для аттестации</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Хранение в личном кабинете</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать олимпиаду
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">диплом от 169 ₽ · оплата ЮКассой</span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <!-- ВЕЕР ДИПЛОМОВ -->
      <div class="hero-diploma" style="position:absolute;inset:0;padding:0;">
        <div class="diploma-stack">
          <div class="diploma-item diploma-1"><img src="/assets/images/diplomas/previews/diploma-1.svg" alt="Диплом вариант 1"></div>
          <div class="diploma-item diploma-2"><img src="/assets/images/diplomas/previews/diploma-2.svg" alt="Диплом вариант 2"></div>
          <div class="diploma-item diploma-3"><img src="/assets/images/diplomas/previews/diploma-3.svg" alt="Диплом вариант 3"></div>
          <div class="diploma-item diploma-4"><img src="/assets/images/diplomas/previews/diploma-4.svg" alt="Диплом вариант 4"></div>
          <div class="diploma-item diploma-5"><img src="/assets/images/diplomas/previews/diploma-5.svg" alt="Диплом вариант 5"></div>
          <div class="diploma-item diploma-6"><img src="/assets/images/diplomas/previews/diploma-6.svg" alt="Диплом вариант 6"></div>
        </div>
      </div>
      <div class="rd-float-card rd-fc-cat-1">
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Тест за 5 минут</div><div class="rd-fc-s">10 вопросов</div></div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">🎓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Бесплатное участие</div><div class="rd-fc-s">диплом — по желанию</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">🎓</div><div><div class="t">Бесплатное участие</div><div class="s">тест без оплаты</div></div></div>
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">Результат сразу</div><div class="s">за 30 секунд после теста</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Соответствует ФГОС</div><div class="s">для аттестации</div></div></div>
    <div class="rd-usp"><div class="ic">🔒</div><div><div class="t">Безопасная оплата</div><div class="s">ЮКасса · PCI DSS</div></div></div>
  </div>
</div>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог олимпиад</div>
        <h2 class="rd-section-title">Выберите олимпиаду под свою аудиторию</h2>
        <p class="rd-section-sub">Найдено: <strong><?php echo $totalOlympiads; ?></strong> олимпиад. Все с дипломом победителя, призёра или участника.</p>
      </div>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
      <!-- Sidebar фильтры -->
      <aside class="rd-filters" id="rdFiltersPanel">

        <?php if (!empty($audienceCategories)): ?>
        <h4>Аудитория</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo empty($selectedCategory) ? ' active' : ''; ?>">
            <label>
              <a href="/olimpiady/" style="text-decoration:none;color:inherit;">Все олимпиады</a>
            </label>
          </div>
          <?php foreach ($audienceCategories as $ac): ?>
          <div class="rd-chip-row<?php echo $selectedCategory === $ac['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('olimpiady', ['ac' => $ac['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($ac['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($audienceTypes)): ?>
        <h4>Уровень</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceTypes as $at): ?>
          <div class="rd-chip-row<?php echo $selectedType === $at['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('olimpiady', ['ac' => $selectedCategory, 'at' => $at['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($audienceSpecializations)): ?>
        <h4>Специализация</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceSpecializations as $as): ?>
          <div class="rd-chip-row<?php echo $selectedSpec === $as['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('olimpiady', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $as['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($as['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/olimpiady/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Каталог + карточки -->
      <div class="rd-catalog-main">
        <div class="rd-comp-search" style="margin-bottom:16px;">
          <div style="position:relative;">
            <svg style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-400);pointer-events:none;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" id="olympiadSearchInput" placeholder="Поиск по олимпиадам — например, «математика» или «логопедия»" autocomplete="off" style="width:100%;padding:14px 44px 14px 46px;font-size:15px;border:1.5px solid var(--ink-200,#e5e7eb);border-radius:12px;background:#fff;outline:none;transition:border-color .15s, box-shadow .15s;" onfocus="this.style.borderColor='var(--indigo-500,#6366f1)';this.style.boxShadow='0 0 0 4px rgba(99,102,241,.12)';" onblur="this.style.borderColor='var(--ink-200,#e5e7eb)';this.style.boxShadow='none';">
            <button type="button" id="olympiadSearchClear" aria-label="Очистить" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:0;cursor:pointer;padding:8px;color:var(--ink-400);line-height:0;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </div>
          <div id="olympiadSearchStatus" style="display:none;margin-top:10px;font-size:14px;color:var(--ink-500,#6b7280);"></div>
        </div>

        <?php if (empty($olympiads)): ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Олимпиады не найдены</p>
            <p>Попробуйте выбрать другую аудиторию или <a href="/olimpiady/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="olympiadsGrid">
            <?php foreach ($olympiads as $olympiad):
                $audLabel = Olympiad::getAudienceLabel($olympiad['target_audience'] ?? '');
                $oUrl     = '/olimpiady/' . urlencode($olympiad['slug']) . '/';
                $oPrice   = (int)($olympiad['diploma_price'] ?? 169);
            ?>
              <a class="rd-card" href="<?php echo $oUrl; ?>">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <?php if (!empty($audLabel)): ?>
                    <span class="rd-tag indigo"><?php echo htmlspecialchars($audLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($olympiad['subject'])): ?>
                    <span class="rd-tag"><?php echo htmlspecialchars($olympiad['subject'], ENT_QUOTES, 'UTF-8'); ?></span>
                  <?php endif; ?>
                </div>
                <h4><?php echo htmlspecialchars($olympiad['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($olympiad['description'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <div class="rd-card-foot">
                  <div class="rd-price-now">диплом <?php echo number_format($oPrice, 0, ',', ' '); ?> ₽</div>
                  <span class="rd-join-btn">Пройти →</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($hasMore): ?>
            <div id="loadMoreContainer" style="margin-top:24px;text-align:center;">
              <button id="loadMoreBtn" class="rd-load-more" data-offset="<?php echo $perPage; ?>">
                Показать больше олимпиад
              </button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- 4 шага -->
<section class="rd-path rd-section">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Как это работает</div>
      <h2 class="rd-section-title">Четыре шага до диплома</h2>
      <p class="rd-section-sub">От выбора олимпиады до диплома в личном кабинете — 5–10 минут.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите олимпиаду</h4>
        <p>Используйте фильтры по аудитории и предмету.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Пройдите тест</h4>
        <p>10 вопросов в формате теста. Время не ограничено. Бесплатно.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Узнайте результат</h4>
        <p>Сразу после теста — место и количество правильных ответов.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Оформите диплом</h4>
        <p>По желанию: именной диплом за 169&nbsp;₽ — сразу в кабинет.</p>
      </div>
    </div>
  </div>
</section>

<!-- Trust band -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal">
      <div class="rd-trust-grid">
        <div>
          <div class="rd-eyebrow">Документы и аккредитации</div>
          <h2>Дипломы соответствуют ФГОС и принимаются при аттестации</h2>
          <p>Мы — официальное СМИ и резидент Сколково с лицензией на образовательную деятельность. Каждый диплом можно проверить по реестру.</p>
        </div>
        <div class="rd-tc-grid">
          <div class="rd-tc"><div class="badge">📜</div><h5>Лицензия</h5><p>№ Л035-01212-59 от 17.12.2021</p></div>
          <div class="rd-tc"><div class="badge">📰</div><h5>СМИ</h5><p>Эл. №ФС 77-74524 от 24.12.2018</p></div>
          <div class="rd-tc"><div class="badge">⚡</div><h5>Сколково</h5><p>Резидент №1127165 от 18.02.2025</p></div>
          <div class="rd-tc"><div class="badge">✓</div><h5>ФГОС</h5><p>Дипломы соответствуют стандарту</p></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-faq">
      <div class="reveal">
        <div class="rd-eyebrow">FAQ</div>
        <h2 class="rd-section-title">Вопросы об олимпиадах</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Участие действительно бесплатное? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Прохождение теста олимпиады — полностью бесплатное. Оплата требуется только если вы захотите получить именной диплом (от 169 ₽).</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Сколько вопросов в олимпиаде? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>10 вопросов с вариантами ответов. Время прохождения не ограничено, но рекомендуем сосредоточенно.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как определяется место? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>9–10 правильных ответов — 1 место, 8 — 2 место, 7 — 3 место. Менее 7 — статус участника.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Можно пройти повторно? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Каждая попытка — новый набор вопросов. При оформлении диплома будет использован лучший результат.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Подходит ли диплом для аттестации? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Дипломы выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и соответствуют ФГОС.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как быстро придёт диплом? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Сразу после оплаты — в личный кабинет, в течение 30 секунд.</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Final CTA -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Готовы попробовать?</div>
        <h2>Выберите олимпиаду и пройдите тест прямо сейчас</h2>
        <p><?php echo $totalOlympiads; ?>+ активных олимпиад. Тест бесплатный — диплом по желанию.</p>
      </div>
      <div class="actions">
        <a href="#catalog" class="rd-btn rd-btn-primary">К каталогу
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/pages/contacts.php" class="rd-btn rd-btn-ghost">Связаться с нами</a>
      </div>
    </div>
  </div>
</section>

<script>
var allOlympiadsData = <?php echo json_encode($allOlympiadsJs, JSON_UNESCAPED_UNICODE); ?>;
var olympiadsPerPage = <?php echo $perPage; ?>;

function _olFmtPrice(num) { return String(num).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
function _olEsc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
function renderOlympiadCard(o) {
    var desc = o.description ? o.description.replace(/<[^>]*>/g, '').substring(0, 120) + '…' : '';
    var tags = '';
    if (o.audience_label) tags += '<span class="rd-tag indigo">' + _olEsc(o.audience_label) + '</span>';
    if (o.subject) tags += '<span class="rd-tag">' + _olEsc(o.subject) + '</span>';
    return '<a class="rd-card" href="' + _olEsc(o.url) + '">' +
        '<div class="rd-card-pat"></div>' +
        '<div class="rd-card-tags">' + tags + '</div>' +
        '<h4>' + _olEsc(o.title) + '</h4>' +
        '<div class="rd-card-meta">' + _olEsc(desc) + '</div>' +
        '<div class="rd-card-foot">' +
          '<div class="rd-price-now">диплом ' + _olFmtPrice(Math.round(o.price)) + ' ₽</div>' +
          '<span class="rd-join-btn">Пройти →</span>' +
        '</div>' +
      '</a>';
}

// Поиск по олимпиадам
(function() {
    var input = document.getElementById('olympiadSearchInput');
    var clearBtn = document.getElementById('olympiadSearchClear');
    var status = document.getElementById('olympiadSearchStatus');
    var grid = document.getElementById('olympiadsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    if (!input || !grid) return;

    var originalGridHtml = null;
    var debounceTimer = null;

    function normalize(s) { return (s || '').toString().toLowerCase().replace(/ё/g, 'е').trim(); }

    function applyFilter(q) {
        q = normalize(q);
        if (!q) {
            if (originalGridHtml !== null) { grid.innerHTML = originalGridHtml; originalGridHtml = null; }
            if (loadMoreContainer) loadMoreContainer.style.display = '';
            status.style.display = 'none';
            clearBtn.style.display = 'none';
            return;
        }
        if (originalGridHtml === null) originalGridHtml = grid.innerHTML;
        clearBtn.style.display = '';
        if (loadMoreContainer) loadMoreContainer.style.display = 'none';

        var tokens = q.split(/\s+/).filter(Boolean);
        var matches = allOlympiadsData.filter(function(o) {
            var hay = normalize((o.title || '') + ' ' + (o.description || '') + ' ' + (o.audience_label || '') + ' ' + (o.subject || ''));
            return tokens.every(function(t) { return hay.indexOf(t) !== -1; });
        });

        if (matches.length === 0) {
            grid.innerHTML = '';
            status.style.display = '';
            status.innerHTML = 'По запросу «' + _olEsc(q) + '» ничего не найдено. Попробуйте другие слова или <a href="#" id="olSearchResetLink" style="color:var(--indigo-600);">сбросьте поиск</a>.';
            var rl = document.getElementById('olSearchResetLink');
            if (rl) rl.addEventListener('click', function(e) { e.preventDefault(); input.value = ''; applyFilter(''); input.focus(); });
            return;
        }
        grid.innerHTML = matches.map(renderOlympiadCard).join('');
        status.style.display = '';
        var n = matches.length;
        var word = (n % 10 === 1 && n % 100 !== 11) ? 'олимпиада' : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'олимпиады' : 'олимпиад');
        status.textContent = 'Найдено: ' + n + ' ' + word;
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var v = input.value;
        debounceTimer = setTimeout(function() { applyFilter(v); }, 120);
    });
    clearBtn.addEventListener('click', function() { input.value = ''; applyFilter(''); input.focus(); });
    input.addEventListener('keydown', function(e) { if (e.key === 'Escape' && input.value) { input.value = ''; applyFilter(''); } });
})();

// Load more
(function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var grid = document.getElementById('olympiadsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    if (!loadMoreBtn || !grid) return;

    var remaining = allOlympiadsData.slice(olympiadsPerPage);
    var currentOffset = 0;

    loadMoreBtn.addEventListener('click', function() {
        var batch = remaining.slice(currentOffset, currentOffset + olympiadsPerPage);
        if (batch.length === 0) return;
        loadMoreBtn.disabled = true;
        loadMoreBtn.textContent = 'Загрузка...';
        grid.insertAdjacentHTML('beforeend', batch.map(renderOlympiadCard).join(''));
        currentOffset += olympiadsPerPage;
        if (currentOffset >= remaining.length) {
            loadMoreContainer.style.display = 'none';
        } else {
            loadMoreBtn.disabled = false;
            loadMoreBtn.textContent = 'Показать больше олимпиад';
        }
    });
})();
</script>

<?php include __DIR__ . '/includes/footer-redesign.php'; ?>

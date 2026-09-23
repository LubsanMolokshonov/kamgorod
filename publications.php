<?php
/**
 * Каталог опубликованных материалов (редизайн)
 */

session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Publication.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';
require_once __DIR__ . '/includes/catalog-seo.php';
require_once __DIR__ . '/includes/catalog-cards.php';

// Фильтры аудитории из URL
$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
$catalogOptions = ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec];
$catalogRequest = catalogRequest($db, 'publikacii', $catalogOptions);
$catalogListing = new CatalogListing($db, 'publikacii', $catalogOptions, $catalogRequest['q']);

redirectToSeoUrl('publikacii', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

// SEO-мета
$pageTitle       = 'Опубликованные материалы педагогов — научный журнал | ' . SITE_NAME;
$pageDescription = 'Каталог опубликованных материалов педагогов: методические разработки, конспекты уроков, программы. Публикация в научном журнале с выдачей сертификата.';
$canonicalUrl    = SITE_URL . '/publikacii/';
$ogImage         = SITE_URL . '/assets/images/og-journal.jpg';
$rdActivePage    = 'publikacii';

$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/assets/css/journal-redesign.css'),
    '/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/assets/css/audience-filter.css'),
    '/assets/css/publication-extras.css?v=' . filemtime(__DIR__ . '/assets/css/publication-extras.css'),
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Пагинация
$perPage = CatalogListing::PAGE_SIZE;

// Аудитория (3-уровневая сегментация)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('publication');

$selectedCategoryData    = null;
$audienceTypes           = [];
$selectedTypeData        = null;
$audienceSpecializations = [];

// Категория аудитории не показывается в UI — автоматически берём первую доступную (обычно «Педагогам»),
// чтобы подгрузить список уровней. ВАЖНО: эта авто-подстановка нужна только для UI меню уровней,
// в фильтр запроса она НЕ попадает — иначе на корневом /publikacii/ скрывались бы все публикации
// без проставленной audience-категории.
$categoryExplicit = !empty($_GET['ac']);
if (!$selectedCategory && !empty($audienceCategories)) {
    $selectedCategory = $audienceCategories[0]['slug'];
}

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

// Фильтры для запроса публикаций
$filters = [];
if ($categoryExplicit && $selectedCategoryData) {
    $filters['category_id'] = $selectedCategoryData['id'];
}
if (!empty($selectedType)) {
    $selectedTypeDataForFilter = $audienceTypeObj->getBySlug($selectedType);
    if ($selectedTypeDataForFilter) {
        $filters['audience_type_id'] = $selectedTypeDataForFilter['id'];
    }
}
if (!empty($selectedSpec) && !empty($audienceSpecializations)) {
    foreach ($audienceSpecializations as $spec) {
        if ($spec['slug'] === $selectedSpec) {
            $filters['specialization_id'] = $spec['id'];
            break;
        }
    }
}

$totalPublications = $catalogListing->count();
if ($catalogRequest['page'] > max(1, (int)ceil($totalPublications / CatalogListing::PAGE_SIZE))) catalogNotFound();
$publications = $catalogListing->page($catalogRequest['page']);
$hasMore = $totalPublications > $catalogRequest['page'] * CatalogListing::PAGE_SIZE;

$catalogPolicy = catalogPolicy($db, 'publikacii', $catalogOptions, $totalPublications, $catalogRequest['page']);
$canonicalUrl = $catalogPolicy['canonical'];
$robotsContent = $catalogRequest['q'] !== '' ? 'noindex,follow' : $catalogPolicy['robots'];
if ($catalogRequest['page'] > 1) $pageTitle .= ' — страница ' . $catalogRequest['page'];
$additionalJS[] = '/assets/js/catalog-pagination.js';
$additionalCSS[] = '/assets/css/catalog-pagination.css';

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">

  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span><?php echo $totalPublications; ?>+ материалов</span>
        <span class="rd-pill indigo">Свидетельство СМИ</span>
        <span class="rd-pill">Резидент Сколково</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal">Опубликованные материалы педагогов&nbsp;<span class="accent">в&nbsp;научном журнале</span></h1>
      <p class="rd-hero-sub reveal">Методические разработки, конспекты уроков, программы и проекты, опубликованные в нашем зарегистрированном электронном СМИ. Бесплатная публикация с выдачей сертификата.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Бесплатная публикация</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Сертификат СМИ Эл. №ФС 77-74524</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Бессрочное хранение в архиве журнала</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Индексация поисковыми системами</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="/opublikovat/" class="rd-btn rd-btn-primary">Опубликовать свой материал
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">бесплатно · сертификат СМИ</span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-journal reveal">
      <div class="rd-blob"></div>
      <!-- ВЕЕР СВИДЕТЕЛЬСТВ О ПУБЛИКАЦИИ -->
      <div class="hero-diploma" style="position:absolute;inset:0;padding:0;">
        <div class="diploma-stack">
          <?php
          $certData = [
              ['name' => 'Иванова Мария Александровна',  'work' => 'Игровые технологии на уроках математики',                       'org' => 'МАОУ Гимназия №7, Пермь'],
              ['name' => 'Козлова Анна Викторовна',      'work' => 'Развитие речи дошкольников через театрализованную деятельность', 'org' => 'МБДОУ ДС №45, Новосибирск'],
              ['name' => 'Смирнова Ольга Николаевна',    'work' => 'Проектная деятельность как средство развития интереса',         'org' => 'МБОУ Лицей №3, Екатеринбург'],
              ['name' => 'Петрова Елена Сергеевна',      'work' => 'Формирование читательской грамотности школьников',              'org' => 'МБОУ СОШ №12, Казань'],
              ['name' => 'Соколов Дмитрий Игоревич',     'work' => 'Цифровые инструменты в преподавании истории',                   'org' => 'МБОУ Гимназия №1, Самара'],
              ['name' => 'Морозова Татьяна Юрьевна',     'work' => 'Формирование УУД на уроках литературы',                          'org' => 'МАОУ СОШ №24, Тюмень'],
          ];
          $certThemes = [
              ['accent' => '#4874FF', 'soft' => '#eef4ff', 'ink' => '#1e2a78'],
              ['accent' => '#7b3ed6', 'soft' => '#f4eefc', 'ink' => '#3b1a78'],
              ['accent' => '#0fa37f', 'soft' => '#e9f7f1', 'ink' => '#0b5a47'],
              ['accent' => '#d8447e', 'soft' => '#fbeef3', 'ink' => '#7a1f49'],
              ['accent' => '#e07a16', 'soft' => '#fcf2e3', 'ink' => '#7a3e0b'],
              ['accent' => '#1e8aa8', 'soft' => '#e6f3f7', 'ink' => '#0e4a5c'],
          ];
          $wrap2 = function($s, $max = 28) {
              $s = trim($s);
              if (mb_strlen($s) <= $max) return [$s, ''];
              $words = explode(' ', $s);
              $line1 = ''; $i = 0;
              while ($i < count($words) && mb_strlen($line1 . ' ' . $words[$i]) <= $max) {
                  $line1 = $line1 === '' ? $words[$i] : $line1 . ' ' . $words[$i];
                  $i++;
              }
              $line2 = trim(implode(' ', array_slice($words, $i)));
              if (mb_strlen($line2) > $max) $line2 = mb_substr($line2, 0, $max - 1) . '…';
              return [$line1, $line2];
          };
          foreach ($certData as $i => $c):
              $idx   = $i + 1;
              $t     = $certThemes[$i];
              [$w1, $w2] = $wrap2('«' . $c['work'] . '»', 30);
              $nm    = htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8');
              $or    = htmlspecialchars($c['org'],  ENT_QUOTES, 'UTF-8');
              $w1    = htmlspecialchars($w1, ENT_QUOTES, 'UTF-8');
              $w2    = htmlspecialchars($w2, ENT_QUOTES, 'UTF-8');
          ?>
          <div class="diploma-item diploma-<?php echo $idx; ?>">
            <svg class="pub-cert-svg" viewBox="0 0 595 842" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Свидетельство о публикации">
              <rect width="595" height="842" fill="<?php echo $t['soft']; ?>"/>
              <rect x="22" y="22" width="551" height="798" fill="#fff" stroke="<?php echo $t['accent']; ?>" stroke-width="3" rx="10"/>
              <rect x="22" y="22" width="551" height="798" fill="none" stroke="<?php echo $t['accent']; ?>" stroke-width="1" stroke-dasharray="2 4" rx="10" opacity=".35"/>
              <rect x="60" y="70" width="475" height="92" fill="<?php echo $t['accent']; ?>" rx="8"/>
              <text x="297.5" y="110" text-anchor="middle" fill="#fff" font-family="Onest, Inter, sans-serif" font-weight="700" font-size="34" letter-spacing="3">СВИДЕТЕЛЬСТВО</text>
              <text x="297.5" y="142" text-anchor="middle" fill="#fff" font-family="Onest, Inter, sans-serif" font-weight="500" font-size="18" letter-spacing="4">О ПУБЛИКАЦИИ</text>
              <text x="297.5" y="220" text-anchor="middle" fill="#5a608a" font-family="Inter, sans-serif" font-size="18">настоящим подтверждается, что</text>
              <text x="297.5" y="282" text-anchor="middle" fill="<?php echo $t['ink']; ?>" font-family="Onest, Inter, sans-serif" font-weight="700" font-size="26"><?php echo $nm; ?></text>
              <text x="297.5" y="338" text-anchor="middle" fill="#5a608a" font-family="Inter, sans-serif" font-size="18">опубликовал(а) методический материал</text>
              <text x="297.5" y="400" text-anchor="middle" fill="#3a3f6b" font-family="Inter, sans-serif" font-style="italic" font-size="22"><?php echo $w1; ?></text>
              <?php if ($w2 !== ''): ?>
              <text x="297.5" y="430" text-anchor="middle" fill="#3a3f6b" font-family="Inter, sans-serif" font-style="italic" font-size="22"><?php echo $w2; ?></text>
              <?php endif; ?>
              <text x="297.5" y="492" text-anchor="middle" fill="#6a6f8e" font-family="Inter, sans-serif" font-size="16"><?php echo $or; ?></text>
              <line x1="120" y1="700" x2="260" y2="700" stroke="<?php echo $t['accent']; ?>" stroke-width="1.5" opacity=".5"/>
              <text x="190" y="722" text-anchor="middle" fill="#6a6f8e" font-family="Inter, sans-serif" font-size="13">Подпись редактора</text>
              <g transform="translate(360 660)">
                <rect x="0" y="0" width="80" height="80" fill="#fff" stroke="<?php echo $t['accent']; ?>" stroke-width="2" rx="4"/>
                <?php
                $cells = [
                    [0,0,1,1,1],[0,1,0,1,0],[1,0,1,0,1],[1,1,0,1,1],[0,1,1,0,1],
                    [1,0,0,1,0],[0,1,1,1,1],[1,1,0,0,1],[0,0,1,1,0],[1,0,1,0,0],
                ];
                for ($r = 0; $r < 10; $r++) {
                  for ($cc = 0; $cc < 10; $cc++) {
                    if (($cells[$r][$cc % 5] ?? 0) === 1) {
                      $cx = 8 + $cc * 6.4;
                      $cy = 8 + $r * 6.4;
                      echo '<rect x="' . $cx . '" y="' . $cy . '" width="6" height="6" fill="' . $t['ink'] . '"/>';
                    }
                  }
                }
                ?>
                <rect x="8" y="8" width="18" height="18" fill="none" stroke="<?php echo $t['ink']; ?>" stroke-width="3"/>
                <rect x="54" y="8" width="18" height="18" fill="none" stroke="<?php echo $t['ink']; ?>" stroke-width="3"/>
                <rect x="8" y="54" width="18" height="18" fill="none" stroke="<?php echo $t['ink']; ?>" stroke-width="3"/>
              </g>
              <text x="400" y="758" text-anchor="middle" fill="#6a6f8e" font-family="Inter, sans-serif" font-size="11">проверьте подлинность</text>
              <text x="44" y="784" fill="#9aa0bf" font-family="Inter, sans-serif" font-size="11">Эл. №ФС 77‑74524</text>
              <text x="551" y="784" text-anchor="end" fill="#9aa0bf" font-family="Inter, sans-serif" font-size="11">fgos.pro/zhurnal</text>
            </svg>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div class="rd-float-card rd-fc-jr-1">
        <div class="rd-fc-icon">📰</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Зарегистрированное СМИ</div><div class="rd-fc-s">Эл. №ФС 77‑74524</div></div>
      </div>
      <div class="rd-float-card rd-fc-jr-2">
        <div class="rd-fc-icon">✓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Сертификат автору</div><div class="rd-fc-s">сразу после публикации</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">🆓</div><div><div class="t">Бесплатная публикация</div><div class="s">без скрытых платежей</div></div></div>
    <div class="rd-usp"><div class="ic">📰</div><div><div class="t">Сертификат СМИ</div><div class="s">от зарегистрированного издания</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Соответствует ФГОС</div><div class="s">для аттестации педагога</div></div></div>
    <div class="rd-usp"><div class="ic">♾️</div><div><div class="t">Бессрочное хранение</div><div class="s">в архиве журнала</div></div></div>
  </div>
</div>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог публикаций</div>
        <h2 class="rd-section-title">Материалы, опубликованные в журнале</h2>
      </div>
      <p class="rd-section-sub">Найдено: <strong id="totalCount"><?php echo $totalPublications; ?></strong> публикаций. Все с открытым доступом.</p>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
      <!-- Поиск (на мобильных — над фильтрами) -->
      <div class="rd-comp-search" style="margin-bottom:16px;">
        <div style="position:relative;">
          <svg style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-400);pointer-events:none;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
          <input type="search" id="publicationSearchInput" placeholder="Поиск по публикациям — например, «методическая разработка» или «дошкольники»" autocomplete="off" style="width:100%;padding:14px 44px 14px 46px;font-size:15px;border:1.5px solid var(--ink-200,#e5e7eb);border-radius:12px;background:#fff;outline:none;transition:border-color .15s, box-shadow .15s;" onfocus="this.style.borderColor='var(--indigo-500,#6366f1)';this.style.boxShadow='0 0 0 4px rgba(99,102,241,.12)';" onblur="this.style.borderColor='var(--ink-200,#e5e7eb)';this.style.boxShadow='none';">
          <button type="button" id="publicationSearchClear" aria-label="Очистить" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:0;cursor:pointer;padding:8px;color:var(--ink-400);line-height:0;">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
          </button>
        </div>
        <div id="publicationSearchStatus" style="display:none;margin-top:10px;font-size:14px;color:var(--ink-500,#6b7280);"></div>
      </div>

      <!-- Sidebar фильтры -->
      <aside class="rd-filters" id="rdFiltersPanel">

        <?php if (!empty($audienceTypes)): ?>
        <h4>Уровень</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo empty($selectedType) ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('publikacii', ['ac' => $selectedCategory]); ?>#catalog" style="text-decoration:none;color:inherit;">Все уровни</a>
            </label>
          </div>
          <?php foreach ($audienceTypes as $at): ?>
          <div class="rd-chip-row<?php echo $selectedType === $at['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('publikacii', ['ac' => $selectedCategory, 'at' => $at['slug']]); ?>#catalog" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/publikacii/#catalog" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Каталог + карточки -->
      <div class="rd-catalog-main">
        <?php if (empty($publications)): ?>
          <div id="publicationsGrid" class="rd-grid"></div>
          <?= renderCatalogPagination($catalogRequest, $totalPublications) ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Публикации не найдены</p>
            <p>Попробуйте выбрать другую категорию или <a href="/publikacii/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="publicationsGrid">
            <?= renderCatalogCards('publikacii', $publications) ?>
          </div>

          <?= renderCatalogPagination($catalogRequest, $totalPublications) ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>



<?php include __DIR__ . '/includes/social-links.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

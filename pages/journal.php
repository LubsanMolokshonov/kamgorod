<?php
/**
 * Journal Landing & Catalog Page (redesigned)
 * /zhurnal/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Publication.php';
require_once __DIR__ . '/../classes/PublicationType.php';
require_once __DIR__ . '/../classes/PublicationTag.php';
require_once __DIR__ . '/../classes/AudienceCategory.php';
require_once __DIR__ . '/../classes/AudienceType.php';
require_once __DIR__ . '/../includes/seo-url.php';
require_once __DIR__ . '/../includes/catalog-meta.php';

$publicationObj = new Publication($db);
$typeObj = new PublicationType($db);
$tagObj = new PublicationTag($db);

$selectedCategory = $_GET['ac'] ?? '';
$selectedType = $_GET['at'] ?? '';
$selectedSpec = $_GET['as'] ?? '';

$tagSlug = $_GET['tag'] ?? null;
$typeSlug = $_GET['type'] ?? null;
$sort = $_GET['sort'] ?? 'date';
$search = $_GET['q'] ?? '';

redirectToSeoUrl('zhurnal', [
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
    'tag' => $tagSlug,
    'type' => $typeSlug,
    'sort' => $sort !== 'date' ? $sort : '',
    'q' => $search,
]);
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 12;
$offset = ($page - 1) * $perPage;

// Audience segmentation (3-level)
$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('publication');

$selectedCategoryData = null;
$audienceTypes = [];
$selectedTypeData = null;
$audienceSpecializations = [];
$selectedSpecData = null;

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

$showLanding = empty($tagSlug) && empty($typeSlug) && empty($search) && empty($selectedCategory) && $page === 1;

$currentTag = null;
$currentType = null;
if ($tagSlug) { $currentTag = $tagObj->getBySlug($tagSlug); }
if ($typeSlug) { $currentType = $typeObj->getBySlug($typeSlug); }

$filters = ['sort' => $sort];
if ($currentTag) { $filters['tag_id'] = $currentTag['id']; }
if ($currentType) { $filters['type_id'] = $currentType['id']; }
if ($selectedCategoryData) { $filters['category_id'] = $selectedCategoryData['id']; }
if ($selectedTypeData) { $filters['audience_type_id'] = $selectedTypeData['id']; }
if (!empty($selectedSpec)) {
    require_once __DIR__ . '/../classes/AudienceSpecialization.php';
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
    if ($selectedSpecData) { $filters['specialization_id'] = $selectedSpecData['id']; }
}

if ($search) {
    $publications = $publicationObj->search($search, $filters, $perPage, $offset);
    $totalCount = count($publicationObj->search($search, $filters, 1000, 0));
} else {
    $publications = $publicationObj->getPublished($perPage, $offset, $filters);
    $totalCount = $publicationObj->countPublished($filters);
}

$totalPages = ceil($totalCount / $perPage);

$subjects = $tagObj->getSubjects();
$types = $typeObj->getWithCounts();

// Page metadata
$pageTitle = 'Педагогический онлайн-журнал — бесплатная публикация статей';
if ($currentTag) {
    $pageTitle = $currentTag['meta_title'] ?: $currentTag['name'] . ' — публикации';
}
if ($currentType) {
    $pageTitle = $currentType['name'] . ' — журнал публикаций';
}
if ($selectedCategoryData || $selectedTypeData || !empty($selectedSpecData)) {
    $audienceLabel = $selectedSpecData['name'] ?? $selectedTypeData['name'] ?? $selectedCategoryData['name'] ?? '';
    if ($audienceLabel) {
        $pageTitle = 'Публикации для ' . mb_strtolower($audienceLabel) . ' — педагогический онлайн-журнал';
    }
}
$pageTitle .= ' | ' . SITE_NAME;

$pageDescription = $currentTag['meta_description'] ?? 'Бесплатная публикация статей, методических разработок и материалов в электронном педагогическом журнале. Получите свидетельство о публикации с QR-кодом.';
if ($selectedCategoryData || $selectedTypeData || !empty($selectedSpecData)) {
    $audienceLabel = $selectedSpecData['name'] ?? $selectedTypeData['name'] ?? $selectedCategoryData['name'] ?? '';
    if ($audienceLabel) {
        $pageDescription = 'Бесплатная публикация статей и методических разработок для ' . mb_strtolower($audienceLabel) . '. Свидетельство о публикации с QR-кодом за 5 минут.';
    }
}

$canonicalPath = '/zhurnal/';
if (!empty($selectedCategory)) {
    $canonicalPath .= $selectedCategory . '/';
    if (!empty($selectedType)) {
        $canonicalPath .= $selectedType . '/';
        if (!empty($selectedSpec)) {
            $canonicalPath .= $selectedSpec . '/';
        }
    }
}
$canonicalUrl = SITE_URL . $canonicalPath;

$rdActivePage = 'zhurnal';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/journal-redesign.css?v=' . filemtime(__DIR__ . '/../assets/css/journal-redesign.css'),
    '/assets/css/audience-filter.css?v=' . filemtime(__DIR__ . '/../assets/css/audience-filter.css'),
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/../assets/js/audience-filter.js')];
$ogImage = SITE_URL . '/assets/images/og-journal.jpg';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'CollectionPage',
    'name' => $pageTitle,
    'description' => $pageDescription,
    'url' => SITE_URL . '/zhurnal/',
    'isPartOf' => [
        '@type' => 'WebSite',
        'name' => SITE_NAME,
        'url' => SITE_URL
    ]
];

// Готовим данные для клиентского поиска по публикациям (когда показан каталог)
$allForSearch = [];
if (!$showLanding) {
    $searchPool = $publicationObj->getPublished(1000, 0, $filters);
    foreach ($searchPool as $p) {
        $allForSearch[] = [
            'id' => $p['id'],
            'title' => $p['title'],
            'author' => $p['author_name'] ?? '',
            'type' => $p['type_name'] ?? '',
            'annotation' => mb_substr(strip_tags($p['annotation'] ?? ''), 0, 160),
            'url' => '/publikaciya/' . $p['slug'] . '/',
            'date' => $p['published_at'],
            'views' => (int)($p['views_count'] ?? 0),
        ];
    }
}

$russianMonths = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня', 'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
function jr_format_date($iso, $months) {
    $date = new DateTime($iso);
    return $date->format('d') . ' ' . $months[$date->format('n') - 1] . ' ' . $date->format('Y');
}
function jr_publications_word($n) {
    $lastDigit = $n % 10;
    $lastTwo = $n % 100;
    if ($lastTwo >= 11 && $lastTwo <= 19) return 'публикаций';
    if ($lastDigit == 1) return 'публикация';
    if ($lastDigit >= 2 && $lastDigit <= 4) return 'публикации';
    return 'публикаций';
}

include __DIR__ . '/../includes/header-redesign.php';
?>

<?php if ($showLanding): ?>
<!-- HERO -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Журнал</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span>Зарегистрированное СМИ</span>
        <span class="rd-pill indigo">Резидент Сколково</span>
        <span class="rd-pill">Принимается при аттестации</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal">Публикуйте статьи в&nbsp;<span class="accent">электронном педагогическом журнале</span></h1>
      <p class="rd-hero-sub reveal">Свидетельство о&nbsp;публикации с&nbsp;QR-кодом — за&nbsp;5&nbsp;минут. Размещение бесплатное, аудитория — педагоги со&nbsp;всей России.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Бесплатное размещение материала</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Свидетельство СМИ Эл. №ФС 77‑74524</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Уникальный номер и QR‑код для проверки</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Принимается при аттестации педагога</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="/opublikovat" class="rd-btn rd-btn-primary">Опубликовать бесплатно
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/generator-statej/" class="rd-btn rd-btn-ghost">Сгенерировать статью за 3 мин</a>
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
          // Простой перенос строки по ширине ~28 символов в две строки
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
                // декоративный QR-паттерн
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
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">5 минут на оформление</div><div class="rd-fc-s">свидетельство с QR</div></div>
      </div>
      <div class="rd-float-card rd-fc-jr-2">
        <div class="rd-fc-icon">📰</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Зарег. СМИ</div><div class="rd-fc-s">Эл. №ФС 77‑74524</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">Быстрая публикация</div><div class="s">модерация до 24 часов</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Свидетельство с QR</div><div class="s">проверяемое по реестру</div></div></div>
    <div class="rd-usp"><div class="ic">🎓</div><div><div class="t">Для аттестации</div><div class="s">принимается комиссиями</div></div></div>
    <div class="rd-usp"><div class="ic">👥</div><div><div class="t">Широкая аудитория</div><div class="s">педагоги по всей России</div></div></div>
  </div>
</div>

<!-- Steps -->
<section class="rd-path rd-section">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Как это работает</div>
      <h2 class="rd-section-title">4 шага до публикации</h2>
      <p class="rd-section-sub">От идеи до свидетельства о&nbsp;публикации — занимает считанные минуты.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Подготовьте материал</h4>
        <p>Статья, методическая разработка, конспект урока — DOC, DOCX или PDF до 10&nbsp;МБ.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Заполните форму</h4>
        <p>Название, краткое описание, тип публикации и направление — форма автоподставит данные.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Загрузите файл</h4>
        <p>Прикрепите документ — модерация занимает до 24 часов.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите свидетельство</h4>
        <p>Оплатите 299&nbsp;₽ и&nbsp;скачайте PDF‑свидетельство с&nbsp;уникальным QR‑кодом.</p>
      </div>
    </div>
    <div style="text-align:center;margin-top:32px;">
      <a href="/opublikovat" class="rd-btn rd-btn-primary">Начать публикацию
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </a>
    </div>
  </div>
</section>

<!-- Опубликованные материалы CTA -->
<section class="rd-section" style="padding-top:0;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Каталог журнала</div>
        <h2>Читайте опубликованные материалы</h2>
        <p>Статьи, методические разработки и&nbsp;другие работы педагогов со&nbsp;всей России.</p>
      </div>
      <div class="actions">
        <a href="/publikacii/" class="rd-btn rd-btn-primary">Смотреть публикации
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
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
          <h2>Свидетельство СМИ и&nbsp;резидент Сколково</h2>
          <p>Журнал — официальное зарегистрированное СМИ. Каждое свидетельство о&nbsp;публикации можно проверить по&nbsp;реестру.</p>
        </div>
        <div class="rd-tc-grid">
          <div class="rd-tc"><div class="badge">📰</div><h5>СМИ</h5><p>Эл. №ФС 77‑74524 от 24.12.2018</p></div>
          <div class="rd-tc"><div class="badge">⚡</div><h5>Сколково</h5><p>Резидент №1127165 от 18.02.2025</p></div>
          <div class="rd-tc"><div class="badge">📜</div><h5>Лицензия</h5><p>№ Л035‑01212‑59 от 17.12.2021</p></div>
          <div class="rd-tc"><div class="badge">✓</div><h5>Соответствие ФГОС</h5><p>Принимается при аттестации</p></div>
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
        <h2 class="rd-section-title">Вопросы о публикации</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304‑44‑13</a>.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Публикация действительно бесплатная? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, размещение материала в&nbsp;журнале полностью бесплатно. Оплачивается только оформление свидетельства о&nbsp;публикации (299&nbsp;₽), если оно вам нужно.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Какие материалы можно публиковать? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Методические разработки, конспекты уроков, статьи, сценарии мероприятий, презентации, рабочие программы и&nbsp;другие авторские педагогические материалы.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как быстро публикуется материал? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Модерация занимает до&nbsp;24&nbsp;часов. После одобрения работа сразу появляется в&nbsp;каталоге журнала.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Подходит ли свидетельство для аттестации? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, наше свидетельство принимается аттестационными комиссиями как подтверждение обобщения и&nbsp;распространения педагогического опыта.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Могу ли я удалить свою публикацию? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, вы можете обратиться в&nbsp;поддержку для удаления или редактирования публикации в&nbsp;любой момент.</div></div>
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
        <div class="rd-eyebrow">Готовы поделиться опытом?</div>
        <h2>Опубликуйте свою работу в&nbsp;журнале</h2>
        <p>Размещение бесплатное, свидетельство о&nbsp;публикации — за&nbsp;5&nbsp;минут.</p>
      </div>
      <div class="actions">
        <a href="/opublikovat" class="rd-btn rd-btn-primary">Опубликовать бесплатно
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/publikacii/" class="rd-btn rd-btn-ghost">Смотреть каталог</a>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php if (!$showLanding): ?>
<!-- CATALOG -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог журнала</div>
        <h2 class="rd-section-title">
          <?php if ($currentTag): echo htmlspecialchars($currentTag['name']);
          elseif ($currentType): echo htmlspecialchars($currentType['name']);
          elseif ($search): ?>Результаты поиска: «<?php echo htmlspecialchars($search); ?>»<?php
          else: ?>Опубликованные материалы<?php endif; ?>
        </h2>
        <p class="rd-section-sub">Найдено: <strong><?php echo $totalCount; ?></strong> <?php echo jr_publications_word($totalCount); ?>.<?php if ($currentTag && $currentTag['description']): ?> <?php echo htmlspecialchars($currentTag['description']); endif; ?></p>
      </div>
      <a href="/opublikovat" class="rd-btn rd-btn-primary head-cta">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"></line><line x1="5" y1="12" x2="19" y2="12"></line></svg>
        Опубликовать
      </a>
    </div>

    <!-- Audience filter -->
    <?php
    $audienceFilterBaseUrl = '/zhurnal';
    $extraPathPrefix = '';
    $extraQueryParams = '';
    if ($tagSlug) $extraQueryParams .= '&tag=' . urlencode($tagSlug);
    if ($typeSlug) $extraQueryParams .= '&type=' . urlencode($typeSlug);
    if ($search) $extraQueryParams .= '&q=' . urlencode($search);
    ?>
    <div class="zhurnal-redesign">
      <?php include __DIR__ . '/../includes/audience-filter.php'; ?>
    </div>

    <div class="rd-catalog">
      <!-- Sidebar -->
      <aside class="rd-filters" id="rdFiltersPanel">
        <h4>Тип публикации</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo !$currentType ? ' active' : ''; ?>">
            <label><a href="<?php echo buildUrl(['type' => null]); ?>" style="text-decoration:none;color:inherit;">Все типы</a></label>
          </div>
          <?php foreach ($types as $type): ?>
          <div class="rd-chip-row<?php echo $typeSlug === $type['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildUrl(['type' => $type['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($type['name']); ?>
                <?php if ($type['publications_count'] > 0): ?>
                  <span style="opacity:.6;"><?php echo $type['publications_count']; ?></span>
                <?php endif; ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <?php if (!empty($subjects)): ?>
        <h4>Предметы</h4>
        <div class="rd-chip-list">
          <?php foreach ($subjects as $tag): ?>
          <div class="rd-chip-row<?php echo $tagSlug === $tag['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildUrl(['tag' => $tag['slug']]); ?>" style="text-decoration:none;color:inherit;">
                <?php echo htmlspecialchars($tag['name']); ?>
                <?php if (!empty($tag['publications_count'])): ?>
                  <span style="opacity:.6;"><?php echo $tag['publications_count']; ?></span>
                <?php endif; ?>
              </a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/zhurnal/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Main -->
      <div class="rd-catalog-main">
        <!-- Search -->
        <div class="rd-comp-search" style="margin-bottom:16px;">
          <div style="position:relative;">
            <svg style="position:absolute;left:16px;top:50%;transform:translateY(-50%);color:var(--ink-400);pointer-events:none;" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>
            <input type="search" id="publicationSearchInput" placeholder="Поиск по публикациям — например, «методическая разработка» или «дошкольники»" autocomplete="off" value="<?php echo htmlspecialchars($search); ?>" style="width:100%;padding:14px 44px 14px 46px;font-size:15px;border:1.5px solid var(--ink-200,#e5e7eb);border-radius:12px;background:#fff;outline:none;transition:border-color .15s, box-shadow .15s;" onfocus="this.style.borderColor='var(--indigo-500,#6366f1)';this.style.boxShadow='0 0 0 4px rgba(99,102,241,.12)';" onblur="this.style.borderColor='var(--ink-200,#e5e7eb)';this.style.boxShadow='none';">
            <button type="button" id="publicationSearchClear" aria-label="Очистить" style="display:none;position:absolute;right:10px;top:50%;transform:translateY(-50%);background:transparent;border:0;cursor:pointer;padding:8px;color:var(--ink-400);line-height:0;">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18M6 6l12 12"/></svg>
            </button>
          </div>
          <div id="publicationSearchStatus" style="display:none;margin-top:10px;font-size:14px;color:var(--ink-500,#6b7280);"></div>
        </div>

        <!-- Sort -->
        <div class="rd-sort-bar">
          <span class="results-count"><?php echo $totalCount; ?> <?php echo jr_publications_word($totalCount); ?></span>
          <div class="sort-options">
            <span class="sort-label">Сортировка:</span>
            <a href="<?php echo buildUrl(['sort' => 'date']); ?>" class="sort-option<?php echo $sort === 'date' ? ' active' : ''; ?>">По дате</a>
            <a href="<?php echo buildUrl(['sort' => 'popular']); ?>" class="sort-option<?php echo $sort === 'popular' ? ' active' : ''; ?>">По популярности</a>
          </div>
        </div>

        <?php if (empty($publications)): ?>
          <div class="rd-empty-state">
            <div class="ic">
              <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
              </svg>
            </div>
            <h3>Публикаций не&nbsp;найдено</h3>
            <p>Попробуйте сбросить фильтры или станьте первым автором в&nbsp;этом разделе!</p>
            <div class="actions">
              <a href="/opublikovat" class="rd-btn rd-btn-primary">Опубликовать статью</a>
              <a href="/zhurnal/" class="rd-btn rd-btn-ghost">Сбросить фильтры</a>
            </div>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="publicationsGrid">
            <?php foreach ($publications as $pub): ?>
              <a class="rd-card pub-card" href="/publikaciya/<?php echo urlencode($pub['slug']); ?>/">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <?php if (!empty($pub['type_name'])): ?>
                    <span class="rd-tag indigo"><?php echo htmlspecialchars($pub['type_name']); ?></span>
                  <?php endif; ?>
                </div>
                <h4><?php echo htmlspecialchars($pub['title']); ?></h4>
                <?php if (!empty($pub['annotation'])): ?>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 130) . (mb_strlen($pub['annotation']) > 130 ? '…' : '')); ?>
                </div>
                <?php endif; ?>
                <div class="pub-author"><?php echo htmlspecialchars($pub['author_name']); ?></div>
                <div class="pub-meta-line">
                  <span><?php echo jr_format_date($pub['published_at'], $russianMonths); ?></span>
                  <span class="meta-views">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                    <?php echo number_format($pub['views_count']); ?>
                  </span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <!-- Pagination -->
          <?php if ($totalPages > 1): ?>
            <nav class="rd-pagination">
              <?php if ($page > 1): ?>
                <a href="<?php echo buildUrl(['page' => $page - 1]); ?>" class="page-link">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="15 18 9 12 15 6"></polyline></svg>
                  Назад
                </a>
              <?php endif; ?>
              <?php
              $start = max(1, $page - 2);
              $end = min($totalPages, $page + 2);
              if ($start > 1) {
                  echo '<a href="' . buildUrl(['page' => 1]) . '" class="page-link">1</a>';
                  if ($start > 2) echo '<span class="page-dots">…</span>';
              }
              for ($i = $start; $i <= $end; $i++): ?>
                <a href="<?php echo buildUrl(['page' => $i]); ?>" class="page-link<?php echo $i === $page ? ' active' : ''; ?>"><?php echo $i; ?></a>
              <?php endfor;
              if ($end < $totalPages) {
                  if ($end < $totalPages - 1) echo '<span class="page-dots">…</span>';
                  echo '<a href="' . buildUrl(['page' => $totalPages]) . '" class="page-link">' . $totalPages . '</a>';
              }
              ?>
              <?php if ($page < $totalPages): ?>
                <a href="<?php echo buildUrl(['page' => $page + 1]); ?>" class="page-link">
                  Далее
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 18 15 12 9 6"></polyline></svg>
                </a>
              <?php endif; ?>
            </nav>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Final CTA на каталоге -->
<section class="rd-section" style="padding-bottom:64px;">
  <div class="rd-wrap">
    <div class="rd-final-cta reveal">
      <div>
        <div class="rd-eyebrow">Готовы поделиться?</div>
        <h2>Опубликуйте свою работу</h2>
        <p>Размещение бесплатное, свидетельство — за&nbsp;5&nbsp;минут.</p>
      </div>
      <div class="actions">
        <a href="/opublikovat" class="rd-btn rd-btn-primary">Опубликовать бесплатно</a>
      </div>
    </div>
  </div>
</section>

<script>
var allPublicationsData = <?php echo json_encode($allForSearch, JSON_UNESCAPED_UNICODE); ?>;

(function() {
    var input = document.getElementById('publicationSearchInput');
    var clearBtn = document.getElementById('publicationSearchClear');
    var status = document.getElementById('publicationSearchStatus');
    var grid = document.getElementById('publicationsGrid');
    if (!input || !grid) return;

    var originalGridHtml = null;
    var debounceTimer = null;

    function _esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function normalize(s) { return (s || '').toString().toLowerCase().replace(/ё/g, 'е').trim(); }

    function fmtNumber(n) { return String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); }
    function fmtDate(iso) {
        try {
            var d = new Date(iso);
            var months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
            return ('0' + d.getDate()).slice(-2) + ' ' + months[d.getMonth()] + ' ' + d.getFullYear();
        } catch(e) { return ''; }
    }

    function renderCard(p) {
        var ann = p.annotation ? p.annotation.substring(0, 130) + (p.annotation.length > 130 ? '…' : '') : '';
        return '<a class="rd-card pub-card" href="' + _esc(p.url) + '">' +
            '<div class="rd-card-pat"></div>' +
            (p.type ? '<div class="rd-card-tags"><span class="rd-tag indigo">' + _esc(p.type) + '</span></div>' : '') +
            '<h4>' + _esc(p.title) + '</h4>' +
            (ann ? '<div class="rd-card-meta">' + _esc(ann) + '</div>' : '') +
            '<div class="pub-author">' + _esc(p.author) + '</div>' +
            '<div class="pub-meta-line">' +
              '<span>' + fmtDate(p.date) + '</span>' +
              '<span class="meta-views"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>' + fmtNumber(p.views) + '</span>' +
            '</div>' +
          '</a>';
    }

    function applyFilter(q) {
        q = normalize(q);
        if (!q) {
            if (originalGridHtml !== null) { grid.innerHTML = originalGridHtml; originalGridHtml = null; }
            status.style.display = 'none';
            clearBtn.style.display = 'none';
            return;
        }
        if (originalGridHtml === null) originalGridHtml = grid.innerHTML;
        clearBtn.style.display = '';

        var tokens = q.split(/\s+/).filter(Boolean);
        var matches = allPublicationsData.filter(function(p) {
            var hay = normalize((p.title || '') + ' ' + (p.author || '') + ' ' + (p.annotation || '') + ' ' + (p.type || ''));
            return tokens.every(function(t) { return hay.indexOf(t) !== -1; });
        });

        if (matches.length === 0) {
            grid.innerHTML = '';
            status.style.display = '';
            status.innerHTML = 'По запросу «' + _esc(q) + '» ничего не найдено. <a href="#" id="pubSearchResetLink" style="color:var(--indigo-600);">Сбросить</a>';
            var rl = document.getElementById('pubSearchResetLink');
            if (rl) rl.addEventListener('click', function(e) { e.preventDefault(); input.value = ''; applyFilter(''); input.focus(); });
            return;
        }
        grid.innerHTML = matches.map(renderCard).join('');
        status.style.display = '';
        var n = matches.length;
        var word = (n % 10 === 1 && n % 100 !== 11) ? 'публикация' : ((n % 10 >= 2 && n % 10 <= 4 && (n % 100 < 10 || n % 100 >= 20)) ? 'публикации' : 'публикаций');
        status.textContent = 'Найдено: ' + n + ' ' + word;
    }

    input.addEventListener('input', function() {
        clearTimeout(debounceTimer);
        var v = input.value;
        debounceTimer = setTimeout(function() { applyFilter(v); }, 120);
    });
    clearBtn.addEventListener('click', function() { input.value = ''; applyFilter(''); input.focus(); });
    input.addEventListener('keydown', function(e) { if (e.key === 'Escape' && input.value) { input.value = ''; applyFilter(''); } });

    // Если уже есть значение из URL — сразу применим
    if (input.value) applyFilter(input.value);
})();
</script>
<?php endif; /* !$showLanding */ ?>

<?php
function buildUrl($params = []) {
    global $tagSlug, $typeSlug, $sort, $search, $page, $selectedCategory, $selectedType, $selectedSpec;

    $current = [];
    if ($selectedCategory) $current['ac'] = $selectedCategory;
    if ($selectedType) $current['at'] = $selectedType;
    if ($selectedSpec) $current['as'] = $selectedSpec;
    if ($tagSlug) $current['tag'] = $tagSlug;
    if ($typeSlug) $current['type'] = $typeSlug;
    if ($sort !== 'date') $current['sort'] = $sort;
    if ($search) $current['q'] = $search;

    $merged = array_merge($current, $params);
    $merged = array_filter($merged, function($v) { return $v !== null && $v !== ''; });
    if (isset($merged['page']) && $merged['page'] == 1) { unset($merged['page']); }

    $path = '/zhurnal';
    $ac = $merged['ac'] ?? '';
    $at = $merged['at'] ?? '';
    $as = $merged['as'] ?? '';
    if ($ac) {
        $path .= '/' . rawurlencode($ac);
        if ($at) {
            $path .= '/' . rawurlencode($at);
            if ($as) { $path .= '/' . rawurlencode($as); }
        }
    }
    $path .= '/';

    $queryParams = array_diff_key($merged, array_flip(['ac', 'at', 'as']));
    $query = http_build_query($queryParams);
    return $path . ($query ? '?' . $query : '') . '#catalog';
}
?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

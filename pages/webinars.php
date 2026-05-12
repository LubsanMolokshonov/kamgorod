<?php
/**
 * Webinars Catalog Page (/vebinary/) — редизайн.
 * Использует header-redesign.php / footer-redesign.php и rd-* классы.
 */

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../classes/Database.php";
require_once __DIR__ . "/../classes/Webinar.php";
require_once __DIR__ . "/../classes/AudienceCategory.php";
require_once __DIR__ . "/../classes/AudienceType.php";
require_once __DIR__ . "/../includes/seo-url.php";
require_once __DIR__ . "/../includes/catalog-meta.php";

$webinarObj = new Webinar($db);

// Маппинг sc (URL slug) → status (internal key) для SEO URL из .htaccess
if (isset($_GET['sc'])) {
    $scMap = defined('WEBINAR_STATUS_URL_REVERSE') ? WEBINAR_STATUS_URL_REVERSE : [];
    $_GET['status'] = $scMap[$_GET['sc']] ?? '';
}

$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';
$status           = $_GET["status"] ?? "";

redirectToSeoUrl('vebinary', [
    'status' => $status,
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('webinar');

$selectedCategoryData = null;
$audienceTypes = [];
$selectedTypeData = null;
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

$filters = [];
if ($status) $filters["status"] = $status;
if ($selectedCategoryData) $filters['category_id'] = $selectedCategoryData['id'];
if ($selectedTypeData)     $filters['audience_type_id'] = $selectedTypeData['id'];
if (!empty($selectedSpec)) {
    require_once __DIR__ . "/../classes/AudienceSpecialization.php";
    $specObj = new AudienceSpecialization($db);
    $selectedSpecData = $specObj->getBySlug($selectedSpec);
    if (!empty($selectedSpecData)) {
        $filters['specialization_id'] = $selectedSpecData['id'];
    }
}

$webinars = $webinarObj->getAll($filters, 50);
$totalWebinars = count($webinars);
$counts = $webinarObj->countByStatus();

// Динамические H1/title/description с учётом статуса и аудитории
$webinarStatusLabels = defined('WEBINAR_STATUS_LABELS') ? WEBINAR_STATUS_LABELS : [];
$catalogBase = $webinarStatusLabels[$status] ?? 'Вебинары';
$audiencePhrase = buildAudiencePhrase($selectedCategoryData, $selectedTypeData, $selectedSpecData ?? null);
$hasAnyFilter = !empty($status) || !empty($selectedCategoryData) || !empty($selectedTypeData) || !empty($selectedSpecData);

$meta = buildCatalogMeta([
    'base'             => $catalogBase,
    'audiencePhrase'   => $audiencePhrase,
    'hasFilter'        => $hasAnyFilter,
    'titleSuffix'      => ' | ' . SITE_NAME,
    'descriptionTpl'   => '{h1}. Бесплатное участие, именной сертификат на 2 ак. часа — для аттестации и портфолио.',
    'h1FallbackPrefix' => 'Вебинары для педагогов с ',
    'h1FallbackAccent' => 'именным сертификатом',
]);
$pageTitle       = $meta['title'];
$pageDescription = $meta['description'];
$h1Html          = $meta['h1_html'];
$canonicalUrl    = SITE_URL . '/vebinary/';
$ogImage         = SITE_URL . '/assets/images/og-webinars.jpg';
$rdActivePage    = 'vebinary';

$additionalCSS = [
    "/assets/css/competition-detail.css?v=" . filemtime(__DIR__ . "/../assets/css/competition-detail.css"),
    "/assets/css/webinars-redesign.css?v=" . filemtime(__DIR__ . "/../assets/css/webinars-redesign.css"),
    "/assets/css/audience-filter.css?v=" . filemtime(__DIR__ . "/../assets/css/audience-filter.css"),
];
$additionalJS = ["/assets/js/audience-filter.js?v=" . filemtime(__DIR__ . "/../assets/js/audience-filter.js")];
$earlyHeadScripts = ['<script>' . file_get_contents(__DIR__ . '/../assets/js/catalog-scroll.js') . '</script>'];

include __DIR__ . "/../includes/header-redesign.php";
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Вебинары</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span><?php echo ($counts["upcoming"] + $counts["autowebinars"]); ?> вебинаров доступно</span>
        <span class="rd-pill indigo">Бесплатное участие</span>
        <span class="rd-pill">Сертификат 2 ак. часа</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal"><?php echo $h1Html; ?></h1>
      <p class="rd-hero-sub reveal">Смотрите видеолекции и прямые эфиры от ведущих экспертов в сфере образования. Бесплатное участие, сертификат на 2 ак. часа — для аттестации и портфолио.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Бесплатное участие в прямом эфире</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Видеолекции — смотрите в любое время</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Именной сертификат участника на 2 ак. часа</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Запись эфира и материалы — после участия</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать вебинар
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">Бесплатно · Сертификат от 200 ₽</span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <div class="hero-diploma" style="position:absolute;inset:0;padding:0;">
        <div class="diploma-stack">
          <div class="diploma-item diploma-1"><img src="/assets/images/diplomas/previews/diploma-1.svg" alt="Сертификат вариант 1"></div>
          <div class="diploma-item diploma-2"><img src="/assets/images/diplomas/previews/diploma-2.svg" alt="Сертификат вариант 2"></div>
          <div class="diploma-item diploma-3"><img src="/assets/images/diplomas/previews/diploma-3.svg" alt="Сертификат вариант 3"></div>
          <div class="diploma-item diploma-4"><img src="/assets/images/diplomas/previews/diploma-4.svg" alt="Сертификат вариант 4"></div>
          <div class="diploma-item diploma-5"><img src="/assets/images/diplomas/previews/diploma-5.svg" alt="Сертификат вариант 5"></div>
          <div class="diploma-item diploma-6"><img src="/assets/images/diplomas/previews/diploma-6.svg" alt="Сертификат вариант 6"></div>
        </div>
      </div>
      <div class="rd-float-card rd-fc-cat-1">
        <div class="rd-fc-icon">🎥</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Прямой эфир</div><div class="rd-fc-s">+ запись и материалы</div></div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">📜</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Сертификат 2 ак. ч.</div><div class="rd-fc-s">именной, для портфолио</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">🆓</div><div><div class="t">Бесплатно</div><div class="s">участие в эфирах и видеолекциях</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Сертификат 2 ак. часа</div><div class="s">именной, для аттестации</div></div></div>
    <div class="rd-usp"><div class="ic">🎬</div><div><div class="t">Запись и материалы</div><div class="s">чек-листы, презентации</div></div></div>
    <div class="rd-usp"><div class="ic">⏰</div><div><div class="t">В удобное время</div><div class="s">видеолекции 24/7</div></div></div>
  </div>
</div>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог вебинаров</div>
        <h2 class="rd-section-title">Выберите вебинар или видеолекцию</h2>
        <p class="rd-section-sub">Найдено: <strong><?php echo $totalWebinars; ?></strong>. Все с возможностью получить именной сертификат.</p>
      </div>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <!-- Горизонтальные фильтры (мобильные) -->
    <div class="af-horizontal-only">
      <?php
      $audienceFilterBaseUrl = '/vebinary';
      $extraPathPrefix = getSectionPathPrefix('vebinary', ['status' => $status]);
      include __DIR__ . '/../includes/audience-filter.php';
      ?>
      <div class="af-categories" style="margin-top:8px;">
        <a href="<?php echo buildSeoUrl('vebinary', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo empty($status) ? ' active' : ''; ?>">Все вебинары</a>
        <a href="<?php echo buildSeoUrl('vebinary', ['status' => 'upcoming', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo $status === 'upcoming' ? ' active' : ''; ?>">Предстоящие (<?php echo $counts["upcoming"]; ?>)</a>
        <a href="<?php echo buildSeoUrl('vebinary', ['status' => 'recordings', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo $status === 'recordings' ? ' active' : ''; ?>">Архив записей (<?php echo $counts["recordings"]; ?>)</a>
        <a href="<?php echo buildSeoUrl('vebinary', ['status' => 'videolecture', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>"
           class="af-pill<?php echo $status === 'videolecture' ? ' active' : ''; ?>">Видеолекции (<?php echo $counts["autowebinars"]; ?>)</a>
      </div>
    </div>

    <div class="rd-catalog">
      <!-- Sidebar фильтры (десктоп) -->
      <aside class="rd-filters" id="rdFiltersPanel">
        <?php
        $sidebarExtraFilters = [
            'title' => 'Тип',
            'allLabel' => 'Все вебинары',
            'allUrl' => buildSeoUrl('vebinary', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
            'allActive' => empty($status),
            'links' => [
                [
                    'label' => 'Предстоящие',
                    'url' => buildSeoUrl('vebinary', ['status' => 'upcoming', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
                    'active' => ($status === 'upcoming'),
                    'count' => $counts["upcoming"]
                ],
                [
                    'label' => 'Архив записей',
                    'url' => buildSeoUrl('vebinary', ['status' => 'recordings', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
                    'active' => ($status === 'recordings'),
                    'count' => $counts["recordings"]
                ],
                [
                    'label' => 'Видеолекции',
                    'url' => buildSeoUrl('vebinary', ['status' => 'videolecture', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]),
                    'active' => ($status === 'videolecture'),
                    'count' => $counts["autowebinars"]
                ]
            ]
        ];
        include __DIR__ . '/../includes/sidebar-filter.php';
        ?>
        <a href="/vebinary/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Контент с карточками -->
      <div class="rd-catalog-main">
        <?php if (empty($webinars)): ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Вебинары не найдены</p>
            <p>Попробуйте выбрать другой фильтр или <a href="/vebinary/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="webinarsGrid">
            <?php foreach ($webinars as $webinar):
                $dateInfo = Webinar::formatDateTime($webinar["scheduled_at"]);
                $isUpcoming = in_array($webinar["status"], ["scheduled", "live"]);
                $isAuto     = $webinar["status"] === "videolecture";
                $isFree     = !empty($webinar["is_free"]);
            ?>
              <a class="rd-card rd-card-webinar" href="/vebinar/<?php echo htmlspecialchars($webinar["slug"]); ?>/">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <?php if ($isUpcoming): ?>
                    <span class="rd-tag indigo upcoming">Скоро</span>
                  <?php elseif ($webinar["status"] === "completed"): ?>
                    <span class="rd-tag recording">Запись</span>
                  <?php elseif ($isAuto): ?>
                    <span class="rd-tag auto">Видеолекция</span>
                  <?php endif; ?>
                  <?php if ($isFree): ?>
                    <span class="rd-tag free">Бесплатно</span>
                  <?php endif; ?>
                </div>
                <div class="rd-card-date">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></svg>
                  <?php if ($isAuto): ?>
                    Доступно в любое время
                  <?php else: ?>
                    <?php echo htmlspecialchars($dateInfo["date"]); ?>, <?php echo htmlspecialchars($dateInfo["time"]); ?> МСК
                  <?php endif; ?>
                </div>
                <h4><?php echo htmlspecialchars($webinar["title"]); ?></h4>
                <?php if (!empty($webinar["short_description"])): ?>
                  <div class="rd-card-meta"><?php echo htmlspecialchars(mb_substr($webinar["short_description"], 0, 130)); ?>…</div>
                <?php endif; ?>
                <?php if (!empty($webinar["speaker_name"])): ?>
                  <div class="rd-card-speaker">
                    <?php if (!empty($webinar["speaker_photo"])): ?>
                      <img src="<?php echo htmlspecialchars($webinar["speaker_photo"]); ?>" alt="">
                    <?php endif; ?>
                    <span class="name"><?php echo htmlspecialchars($webinar["speaker_name"]); ?></span>
                  </div>
                <?php endif; ?>
                <div class="rd-card-foot">
                  <div class="rd-meta-row">
                    <span><?php echo (int)$webinar["duration_minutes"]; ?> мин</span>
                    <span><?php echo (int)$webinar["registrations_count"]; ?> участников</span>
                  </div>
                  <span class="rd-join-btn"><?php echo $isUpcoming ? "Зарегистрироваться" : ($isAuto ? "Смотреть" : "Подробнее"); ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
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
      <h2 class="rd-section-title">Четыре шага до сертификата</h2>
      <p class="rd-section-sub">От выбора вебинара до именного сертификата — за один день.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите вебинар</h4>
        <p>Прямой эфир или видеолекция — фильтры по теме и аудитории.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Зарегистрируйтесь</h4>
        <p>Бесплатно. Ссылка на участие придёт на email сразу после регистрации.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Смотрите и пройдите тест</h4>
        <p>Прямой эфир или запись в удобное время. После — короткий тест из 5 вопросов.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите сертификат</h4>
        <p>Именной сертификат участника на 2 ак. часа — в личном кабинете.</p>
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
          <h2>Сертификаты выдаём от зарегистрированного СМИ</h2>
          <p>Мы — официальное СМИ и резидент Сколково с лицензией на образовательную деятельность. Сертификаты участников вебинаров принимаются в портфолио и при аттестации.</p>
        </div>
        <div class="rd-tc-grid">
          <div class="rd-tc"><div class="badge">📜</div><h5>Лицензия</h5><p>№ Л035-01212-59 от 17.12.2021</p></div>
          <div class="rd-tc"><div class="badge">📰</div><h5>СМИ</h5><p>Эл. №ФС 77-74524 от 24.12.2018</p></div>
          <div class="rd-tc"><div class="badge">⚡</div><h5>Сколково</h5><p>Резидент №1127165 от 18.02.2025</p></div>
          <div class="rd-tc"><div class="badge">✓</div><h5>2 ак. часа</h5><p>в каждом сертификате участника</p></div>
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
        <h2 class="rd-section-title">Вопросы о вебинарах</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>. Ежедневно 9:00–21:00.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Участие в вебинаре платное? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Нет, участие в эфирах и видеолекциях бесплатное. Платный — только именной сертификат участника (от 200 ₽).</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как получить ссылку на трансляцию? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>После регистрации ссылка на эфир придёт на email. За сутки и за час до начала отправим напоминание.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Будет ли запись? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. После эфира мы пришлём ссылку на запись и презентацию спикера. Видеолекции изначально доступны 24/7.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Чем отличается вебинар от видеолекции? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Вебинар — это прямой эфир в назначенное время с возможностью задать вопрос спикеру. Видеолекция — готовая запись, которую можно смотреть в любое время.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как получить сертификат? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>После просмотра пройдите короткий тест из 5 вопросов. Затем оформите именной сертификат на 2 ак. часа — он сразу появится в личном кабинете.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Принимается ли сертификат при аттестации? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Сертификаты выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и принимаются в портфолио. Для аттестации лучше сочетать с курсами повышения квалификации в ФИС ФРДО.</div></div>
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
        <div class="rd-eyebrow">Готовы участвовать?</div>
        <h2>Выберите вебинар и получите сертификат</h2>
        <p><?php echo ($counts["upcoming"] + $counts["autowebinars"]); ?>+ вебинаров и видеолекций для педагогов. Бесплатное участие, именной сертификат на 2 ак. часа.</p>
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

<?php include __DIR__ . "/../includes/social-links.php"; ?>

<?php include __DIR__ . "/../includes/footer-redesign.php"; ?>

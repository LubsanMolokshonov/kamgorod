<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/AudienceCategory.php';
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/url-helper.php';
require_once __DIR__ . '/includes/seo-url.php';

// Маппинг cc (URL slug) → category (internal key) для SEO URL из .htaccess
if (isset($_GET['cc'])) {
    $ccMap = defined('COMPETITION_CATEGORY_URL_REVERSE') ? COMPETITION_CATEGORY_URL_REVERSE : [];
    $_GET['category'] = $ccMap[$_GET['cc']] ?? 'all';
}

$category         = $_GET['category'] ?? 'all';
$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

redirectToSeoUrl('konkursy', [
    'category' => $category !== 'all' ? $category : '',
    'ac' => $selectedCategory,
    'at' => $selectedType,
    'as' => $selectedSpec,
]);

$pageTitle       = 'Конкурсы для педагогов и школьников 2025-2026 | ' . SITE_NAME;
$pageDescription = 'Всероссийские и международные конкурсы для учителей, педагогов и школьников. Официальные дипломы соответствуют ФГОС и принимаются при аттестации.';
$canonicalUrl    = SITE_URL . '/konkursy/';
$ogImage         = SITE_URL . '/assets/images/og-competitions.jpg';
$rdActivePage    = 'konkursy';
$additionalCSS   = ['/assets/css/competition-detail.css'];

$perPage = 21;

$validCategories = array_keys(COMPETITION_CATEGORIES);
if ($category !== 'all' && !in_array($category, $validCategories)) {
    $category = 'all';
}

$audienceCatObj = new AudienceCategory($db);
$audienceTypeObj = new AudienceType($db);
$audienceCategories = $audienceCatObj->getAllWithProducts('competition');

$selectedCategoryData   = null;
$audienceTypes          = [];
$selectedTypeData       = null;
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

$competitionObj = new Competition($db);
$filters = [];
if ($selectedCategoryData) $filters['audience_category'] = $selectedCategoryData['id'];
if (!empty($selectedType))  $filters['audience_type'] = $selectedType;
if (!empty($selectedSpec))  $filters['specialization'] = $selectedSpec;
if ($category !== 'all')    $filters['category'] = $category;

$allCompetitions   = !empty($filters)
    ? $competitionObj->getFilteredCompetitions($filters)
    : $competitionObj->getActiveCompetitions($category);

$totalCompetitions = count($allCompetitions);
$competitions      = array_slice($allCompetitions, 0, $perPage);
$hasMore           = $totalCompetitions > $perPage;

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Конкурсы</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span><?php echo $totalCompetitions; ?>+ активных конкурсов</span>
        <span class="rd-pill indigo">Соответствует ФГОС</span>
        <span class="rd-pill">Принимается при аттестации</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal">Конкурсы для педагогов с&nbsp;<span class="accent">дипломом за&nbsp;30&nbsp;секунд</span></h1>
      <p class="rd-hero-sub reveal">Участвуйте, отправляйте работу, получайте официальный диплом для портфолио и аттестации. Дипломы соответствуют ФГОС, выданы зарегистрированным СМИ.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Диплом сразу после оплаты</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Подходит для аттестации педагога</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Хранение в личном кабинете бессрочно</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Акция «2+1»: третий конкурс — бесплатно</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#catalog" class="rd-btn rd-btn-primary">Выбрать конкурс
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">от 169 ₽ · оплата ЮКассой</span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <!-- ВЕЕР ДИПЛОМОВ — НЕ МЕНЯТЬ -->
      <div class="hero-diploma" style="position:absolute;inset:0;padding:0;">
        <div class="diploma-stack">
          <div class="diploma-item diploma-1">
            <img src="/assets/images/diplomas/previews/diploma-1.svg" alt="Диплом вариант 1">
          </div>
          <div class="diploma-item diploma-2">
            <img src="/assets/images/diplomas/previews/diploma-2.svg" alt="Диплом вариант 2">
          </div>
          <div class="diploma-item diploma-3">
            <img src="/assets/images/diplomas/previews/diploma-3.svg" alt="Диплом вариант 3">
          </div>
          <div class="diploma-item diploma-4">
            <img src="/assets/images/diplomas/previews/diploma-4.svg" alt="Диплом вариант 4">
          </div>
          <div class="diploma-item diploma-5">
            <img src="/assets/images/diplomas/previews/diploma-5.svg" alt="Диплом вариант 5">
          </div>
          <div class="diploma-item diploma-6">
            <img src="/assets/images/diplomas/previews/diploma-6.svg" alt="Диплом вариант 6">
          </div>
        </div>
      </div>
      <div class="rd-float-card rd-fc-cat-1">
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Диплом за 30 секунд</div><div class="rd-fc-s">сразу после оплаты</div></div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">✓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Соответствует ФГОС</div><div class="rd-fc-s">для аттестации</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">Диплом сразу</div><div class="s">за 30 секунд после оплаты</div></div></div>
    <div class="rd-usp"><div class="ic">📜</div><div><div class="t">Соответствует ФГОС</div><div class="s">для аттестации педагога</div></div></div>
    <div class="rd-usp"><div class="ic">🎁</div><div><div class="t">Акция «2+1»</div><div class="s">третий конкурс — бесплатно</div></div></div>
    <div class="rd-usp"><div class="ic">🔒</div><div><div class="t">Безопасная оплата</div><div class="s">ЮКасса · PCI DSS</div></div></div>
  </div>
</div>

<!-- Каталог -->
<section class="rd-section" id="catalog">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Каталог конкурсов</div>
        <h2 class="rd-section-title">Выберите конкурс под свой уровень и предмет</h2>
        <p class="rd-section-sub">Найдено: <strong><?php echo $totalCompetitions; ?></strong> конкурсов. Все с дипломом победителя или участника.</p>
      </div>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
      <!-- Sidebar фильтры -->
      <aside class="rd-filters" id="rdFiltersPanel">

        <!-- Категория конкурса -->
        <h4>Категория</h4>
        <div class="rd-chip-list">
          <div class="rd-chip-row<?php echo $category === 'all' ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;">Все конкурсы</a>
            </label>
          </div>
          <?php foreach (COMPETITION_CATEGORIES as $cat => $label): ?>
          <div class="rd-chip-row<?php echo $category === $cat ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $cat, 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Аудитория -->
        <?php if (!empty($audienceCategories)): ?>
        <h4>Аудитория</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceCategories as $ac): ?>
          <div class="rd-chip-row<?php echo $selectedCategory === $ac['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $ac['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($ac['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Тип аудитории (если выбрана категория) -->
        <?php if (!empty($audienceTypes)): ?>
        <h4>Уровень</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceTypes as $at): ?>
          <div class="rd-chip-row<?php echo $selectedType === $at['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $selectedCategory, 'at' => $at['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($at['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Специализации -->
        <?php if (!empty($audienceSpecializations)): ?>
        <h4>Специализация</h4>
        <div class="rd-chip-list">
          <?php foreach ($audienceSpecializations as $as): ?>
          <div class="rd-chip-row<?php echo $selectedSpec === $as['slug'] ? ' active' : ''; ?>">
            <label>
              <a href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '', 'ac' => $selectedCategory, 'at' => $selectedType, 'as' => $as['slug']]); ?>" style="text-decoration:none;color:inherit;"><?php echo htmlspecialchars($as['name'], ENT_QUOTES, 'UTF-8'); ?></a>
            </label>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <a href="/konkursy/" class="rd-reset-btn">Сбросить фильтры</a>
      </aside>

      <!-- Каталог + карточки -->
      <div class="rd-catalog-main">
        <!-- Тулбар -->
        <div class="rd-catalog-toolbar">
          <div class="rd-ct-count">Найдено <strong><?php echo $totalCompetitions; ?></strong> конкурсов</div>
          <div class="rd-applied-tags">
            <?php if ($category !== 'all'): ?>
              <a class="rd-applied-tag" href="<?php echo buildSeoUrl('konkursy', ['ac' => $selectedCategory, 'at' => $selectedType, 'as' => $selectedSpec]); ?>">
                <?php echo htmlspecialchars(COMPETITION_CATEGORIES[$category] ?? $category, ENT_QUOTES, 'UTF-8'); ?> ×
              </a>
            <?php endif; ?>
            <?php if ($selectedCategoryData): ?>
              <a class="rd-applied-tag" href="<?php echo buildSeoUrl('konkursy', ['category' => $category !== 'all' ? $category : '']); ?>">
                <?php echo htmlspecialchars($selectedCategoryData['name'], ENT_QUOTES, 'UTF-8'); ?> ×
              </a>
            <?php endif; ?>
          </div>
        </div>

        <!-- Сетка карточек -->
        <?php if (empty($competitions)): ?>
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Конкурсы не найдены</p>
            <p>Попробуйте выбрать другую категорию или <a href="/konkursy/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="competitionsGrid">
            <?php foreach ($competitions as $competition):
                $compAudienceTypes = $competitionObj->getAudienceTypes($competition['id']);
                $currentContext    = getCurrentAudienceContext();
                $compUrl           = getCompetitionUrl($competition['slug'], $compAudienceTypes, $currentContext);
                $catLabel          = Competition::getCategoryLabel($competition['category']);
            ?>
              <a class="rd-card" href="<?php echo $compUrl; ?>">
                <div class="rd-card-pat"></div>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($catLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <h4><?php echo htmlspecialchars($competition['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php echo htmlspecialchars(mb_substr(strip_tags($competition['description']), 0, 120), ENT_QUOTES, 'UTF-8'); ?>…
                </div>
                <div class="rd-card-foot">
                  <div class="rd-price-now"><?php echo number_format($competition['price'], 0, ',', ' '); ?> ₽</div>
                  <span class="rd-join-btn">Участвовать</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($hasMore): ?>
            <div id="loadMoreContainer" style="margin-top:24px;text-align:center;">
              <button id="loadMoreBtn" class="rd-load-more" data-offset="<?php echo $perPage; ?>">
                Показать больше конкурсов
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
      <p class="rd-section-sub">От выбора конкурса до диплома в личном кабинете — занимает считанные минуты.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите конкурс</h4>
        <p>Используйте фильтры по уровню, предмету и формату — подберём за секунды.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Загрузите работу</h4>
        <p>Метод. разработку, рисунок, проект или видео. Размер до 50 МБ.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Оплатите участие</h4>
        <p>Картой через ЮКассу. Защищено по PCI&nbsp;DSS. От 169&nbsp;₽.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите диплом</h4>
        <p>Сразу после оплаты — в личный кабинет. Скачать можно в любой момент.</p>
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
        <h2 class="rd-section-title">Вопросы о конкурсах</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>. Ежедневно 9:00–21:00.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Подходит ли диплом для аттестации педагога? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Дипломы выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и соответствуют ФГОС. Принимаются при аттестации педагогов и для портфолио.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как быстро приходит диплом? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>В течение 30 секунд после оплаты — диплом появляется в личном кабинете автоматически. Никаких ожиданий и ручной обработки.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Что входит в стоимость участия? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Подача работы, экспертная оценка, диплом победителя/участника в электронном виде с уникальным номером, проверяемым по реестру.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как работает акция «2+1»? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>При оплате двух конкурсов в корзине третий участник добавляется бесплатно. Акция применяется автоматически.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Могу ли я отправить ученика на конкурс? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. На каждом конкурсе указано, для кого он — для педагогов, дошкольников, школьников. Педагог получает диплом куратора.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Какой формат работы принимается? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Зависит от номинации: методическая разработка (PDF/Word), рисунок (JPG/PNG), проект, видео, презентация. Конкретные форматы — в карточке конкурса.</div></div>
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
        <h2>Выберите конкурс и получите диплом сегодня</h2>
        <p><?php echo $totalCompetitions; ?>+ активных конкурсов для педагогов всех уровней. Дипломы соответствуют ФГОС и принимаются при аттестации.</p>
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

<!-- E-commerce: Impressions -->
<script>
window.dataLayer = window.dataLayer || [];
<?php if (!empty($competitions)): ?>
window.dataLayer.push({
  ecommerce: {
    impressions: [
      <?php foreach ($competitions as $i => $c): ?>
      {
        id: '<?php echo $c['id']; ?>',
        name: <?php echo json_encode($c['title'], JSON_UNESCAPED_UNICODE); ?>,
        category: <?php echo json_encode($c['category'] ?? '', JSON_UNESCAPED_UNICODE); ?>,
        price: <?php echo $c['price']; ?>,
        position: <?php echo $i + 1; ?>,
        list: 'Catalog'
      }<?php echo $i < count($competitions) - 1 ? ',' : ''; ?>
      <?php endforeach; ?>
    ]
  }
});
<?php endif; ?>
</script>

<?php include __DIR__ . '/includes/footer-redesign.php'; ?>

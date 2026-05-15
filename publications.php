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

// Фильтры аудитории из URL
$selectedCategory = $_GET['ac'] ?? '';
$selectedType     = $_GET['at'] ?? '';
$selectedSpec     = $_GET['as'] ?? '';

// 301-редирект со старых query-param URL на чистые SEO URL
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
];
$additionalJS = ['/assets/js/audience-filter.js?v=' . filemtime(__DIR__ . '/assets/js/audience-filter.js')];

// Пагинация
$perPage = 21;

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

// Получение публикаций
$publicationObj    = new Publication($db);
// Загружаем большую партию для client-side «Загрузить ещё», но общий счётчик берём отдельно,
// чтобы LIMIT не занижал отображаемое число «Найдено: N».
$allPublications   = $publicationObj->getPublished($perPage + 100, 0, $filters);
$totalPublications = $publicationObj->countPublished($filters);
$publications      = array_slice($allPublications, 0, $perPage);
$hasMore           = $totalPublications > $perPage;

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO каталога -->
<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Публикации</strong>
    </div>
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

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <div class="rd-float-card rd-fc-cat-1">
        <div class="rd-fc-icon">📰</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Зарегистрированное СМИ</div><div class="rd-fc-s">Эл. №ФС 77-74524</div></div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
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
        <p class="rd-section-sub">Найдено: <strong id="totalCount"><?php echo $totalPublications; ?></strong> публикаций. Все с открытым доступом.</p>
      </div>
      <button class="rd-filter-toggle" id="rdFilterToggle" type="button">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M6 12h12M10 18h4"/></svg>
        Фильтры
      </button>
    </div>

    <div class="rd-catalog">
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
          <div style="text-align:center;padding:60px 0;color:var(--ink-500);">
            <p style="font-size:18px;margin-bottom:16px;">Публикации не найдены</p>
            <p>Попробуйте выбрать другую категорию или <a href="/publikacii/" style="color:var(--indigo-600);">сбросить фильтры</a>.</p>
          </div>
        <?php else: ?>
          <div class="rd-grid reveal-stagger" id="publicationsGrid">
            <?php foreach ($publications as $pub):
                $pubDate = date('d.m.Y', strtotime($pub['published_at'] ?? $pub['created_at']));
            ?>
              <a class="rd-card" href="/publikaciya/<?php echo htmlspecialchars($pub['slug'], ENT_QUOTES, 'UTF-8'); ?>/">
                <div class="rd-card-pat"></div>
                <?php if (!empty($pub['type_name'])): ?>
                <div class="rd-card-tags">
                  <span class="rd-tag indigo"><?php echo htmlspecialchars($pub['type_name'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
                <?php endif; ?>
                <h4><?php echo htmlspecialchars($pub['title'], ENT_QUOTES, 'UTF-8'); ?></h4>
                <div class="rd-card-meta">
                  <?php if (!empty($pub['author_name'])): ?>
                    <?php echo htmlspecialchars($pub['author_name'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo $pubDate; ?>
                  <?php else: ?>
                    <?php echo $pubDate; ?>
                  <?php endif; ?>
                  <?php if (!empty($pub['annotation'])): ?>
                    <br><?php echo htmlspecialchars(mb_substr($pub['annotation'], 0, 120), ENT_QUOTES, 'UTF-8'); ?><?php echo mb_strlen($pub['annotation']) > 120 ? '…' : ''; ?>
                  <?php endif; ?>
                </div>
                <div class="rd-card-foot">
                  <?php if (!empty($pub['views_count']) && $pub['views_count'] > 0): ?>
                    <span style="font-size:13px;color:var(--ink-500);">👁 <?php echo (int)$pub['views_count']; ?></span>
                  <?php else: ?>
                    <span></span>
                  <?php endif; ?>
                  <span class="rd-join-btn">Читать</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>

          <?php if ($hasMore): ?>
            <div id="loadMoreContainer" style="margin-top:24px;text-align:center;">
              <button id="loadMoreBtn" class="rd-load-more" data-offset="<?php echo $perPage; ?>">
                Показать больше публикаций
              </button>
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>
</section>

<!-- Клиентская пагинация -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var grid = document.getElementById('publicationsGrid');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    var allPublications = <?php echo json_encode(array_slice($allPublications, $perPage), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
    var perPage = <?php echo $perPage; ?>;
    var currentOffset = 0;

    if (loadMoreBtn && allPublications.length > 0) {
        loadMoreBtn.addEventListener('click', function() {
            var btn = this;
            var batch = allPublications.slice(currentOffset, currentOffset + perPage);
            if (batch.length === 0) return;

            btn.disabled = true;
            btn.textContent = 'Загрузка...';

            var html = '';
            batch.forEach(function(pub) {
                var date = pub.published_at || pub.created_at || '';
                if (date) {
                    var d = new Date(date);
                    date = ('0' + d.getDate()).slice(-2) + '.' + ('0' + (d.getMonth() + 1)).slice(-2) + '.' + d.getFullYear();
                }
                var annotation = pub.annotation || '';
                if (annotation.length > 120) annotation = annotation.substring(0, 120) + '…';

                html += '<a class="rd-card" href="/publikaciya/' + escapeHtml(pub.slug || '') + '/">';
                html += '<div class="rd-card-pat"></div>';
                if (pub.type_name) {
                    html += '<div class="rd-card-tags"><span class="rd-tag indigo">' + escapeHtml(pub.type_name) + '</span></div>';
                }
                html += '<h4>' + escapeHtml(pub.title || '') + '</h4>';
                html += '<div class="rd-card-meta">';
                if (pub.author_name) {
                    html += escapeHtml(pub.author_name) + ' · ' + date;
                } else {
                    html += date;
                }
                if (annotation) {
                    html += '<br>' + escapeHtml(annotation);
                }
                html += '</div>';
                html += '<div class="rd-card-foot">';
                if (pub.views_count > 0) {
                    html += '<span style="font-size:13px;color:var(--ink-500);">👁 ' + pub.views_count + '</span>';
                } else {
                    html += '<span></span>';
                }
                html += '<span class="rd-join-btn">Читать</span>';
                html += '</div>';
                html += '</a>';
            });

            grid.insertAdjacentHTML('beforeend', html);
            currentOffset += perPage;

            if (currentOffset >= allPublications.length) {
                loadMoreContainer.style.display = 'none';
            } else {
                btn.disabled = false;
                btn.textContent = 'Показать больше публикаций';
            }
        });
    }

    // Toggle фильтров на мобильных
    var filterToggle = document.getElementById('rdFilterToggle');
    var filtersPanel = document.getElementById('rdFiltersPanel');
    if (filterToggle && filtersPanel) {
        filterToggle.addEventListener('click', function() {
            filtersPanel.classList.toggle('open');
        });
    }

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }
});
</script>

<?php include __DIR__ . '/includes/social-links.php'; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>

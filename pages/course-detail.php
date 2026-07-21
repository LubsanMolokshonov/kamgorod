<?php
/**
 * Course Detail Page — редизайн
 * Детальная страница курса КПК/ПП в стиле competition-detail.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Course.php';
require_once __DIR__ . '/../classes/CourseExpert.php';
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/installment-helper.php';

$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /kursy');
    exit;
}

$courseObj = new Course($db);
$course = $courseObj->getBySlug($slug);

if (!$course) {
    http_response_code(404);
    $pageTitle = 'Курс не найден | ' . SITE_NAME;
    $pageDescription = 'Запрашиваемый курс не найден';
    $noindex = true;
    $rdActivePage = 'kursy';
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <main>
      <section class="rd-section">
        <div class="rd-wrap" style="text-align:center;">
          <h1 style="font:700 36px var(--font-sans);color:var(--ink-900);margin-bottom:12px;">Курс не найден</h1>
          <p style="color:var(--ink-500);margin-bottom:24px;">Возможно, он был удалён или перемещён.</p>
          <a href="/kursy" class="rd-btn rd-btn-primary">Все курсы</a>
        </div>
      </section>
    </main>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

// Ценообразование (фиксированная скидка / A/B-тест)
$abVariant = CoursePriceAB::getVariant();
$abPrice = CoursePriceAB::getAdjustedPrice((float)$course['price'], $abVariant, $course['program_type'] ?? null);
$abBasePrice = (float)$course['price'];
$hasDiscount = $abVariant !== 'A';
$discountPercent = CoursePriceAB::getDiscountPercent($abVariant, $course['program_type'] ?? null);

// Get course data
$experts = $courseObj->getExperts($course['id']);
$modules = $courseObj->getModules($course);
$outcomes = $courseObj->getOutcomes($course);
$audienceCategories = $courseObj->getAudienceCategories($course['id']);
$audienceTypes = $courseObj->getAudienceTypes($course['id']);
$specializations = $courseObj->getSpecializations($course['id']);

// Page metadata
$programLabel = $course['program_type'] === 'pp'
    ? 'курс профессиональной переподготовки'
    : 'курс повышения квалификации';
$programShortLabel = $course['program_type'] === 'pp'
    ? 'Профпереподготовка'
    : 'Повышение квалификации';
$credentialType = $course['program_type'] === 'pp'
    ? 'Диплом о профессиональной переподготовке'
    : 'Удостоверение о повышении квалификации';
$credentialShort = $course['program_type'] === 'pp' ? 'Диплом' : 'Удостоверение';

$pageTitle = htmlspecialchars($course['title']) . ' — ' . $programLabel . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr(strip_tags($course['description']), 0, 120))
    . '. ' . Course::formatHours($course['hours']) . '. ' . $credentialType . '.';

$courseUrl = SITE_URL . '/kursy/' . $course['slug'] . '/';
$canonicalUrl = $courseUrl;
$ogImage = SITE_URL . '/og-image/course/' . $course['slug'] . '.jpg';
$ogType = 'article';

// JSON-LD Course
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Course',
    'name' => $course['title'],
    'description' => mb_substr(strip_tags($course['description']), 0, 300),
    'url' => $courseUrl,
    'inLanguage' => 'ru',
    'image' => $ogImage,
    'provider' => [
        '@type' => 'Organization',
        'name' => SITE_NAME,
        'url' => SITE_URL . '/',
        'logo' => SITE_URL . '/assets/images/logo.svg'
    ],
    'offers' => [
        '@type' => 'Offer',
        'price' => $abPrice,
        'priceCurrency' => 'RUB',
        'availability' => 'https://schema.org/InStock',
        'url' => $courseUrl
    ],
    'hasCourseInstance' => [
        '@type' => 'CourseInstance',
        'courseMode' => 'online',
        'courseWorkload' => 'PT' . $course['hours'] . 'H'
    ],
    'educationalCredentialAwarded' => [
        '@type' => 'EducationalOccupationalCredential',
        'name' => $credentialType,
        'credentialCategory' => 'professional-certification',
        'recognizedBy' => [
            '@type' => 'Organization',
            'name' => SITE_NAME,
            'url' => SITE_URL . '/'
        ]
    ],
    'numberOfCredits' => (int)$course['hours'],
    'educationalLevel' => 'Дополнительное профессиональное образование',
    'isAccessibleForFree' => false
];

if (!empty($experts)) {
    $instructors = [];
    foreach ($experts as $expert) {
        $instructor = ['@type' => 'Person', 'name' => $expert['full_name']];
        if (!empty($expert['credentials'])) {
            $instructor['jobTitle'] = $expert['credentials'];
        }
        $instructors[] = $instructor;
    }
    $jsonLd['hasCourseInstance']['instructor'] = count($instructors) === 1 ? $instructors[0] : $instructors;
}

if (!empty($modules)) {
    $jsonLd['syllabusSections'] = array_map(function($m) { return $m['title']; }, $modules);
}

// FAQ-блок + микроразметка Schema.org/FAQPage.
// Гибрид: пул с названием курса ({product}) → детерминированный поднабор по id курса.
require_once __DIR__ . '/../includes/faq-helper.php';
require_once __DIR__ . '/../includes/faq-pool-helper.php';
$faqPool = [
    ['q' => 'Какой документ я получу после курса «{product}»?', 'a' => $credentialType . ' установленного образца. Данные вносятся в ФИС ФРДО (Федеральный реестр). Документ принимается при аттестации и любой проверке.'],
    ['q' => 'Как проходит обучение на курсе «{product}»?', 'a' => 'Обучение проходит полностью дистанционно. После оплаты с вами связывается методист и открывает доступ к учебным материалам — дальше вы изучаете их в удобном темпе, без отрыва от работы.'],
    ['q' => 'Принимает ли работодатель такой документ?', 'a' => 'Да. Мы имеем разрешение Фонда «Сколково» № 068 на осуществление образовательной деятельности по 66 программам — таких организаций в России единицы. Документ принимается всеми образовательными организациями, учитывается при аттестации и проверках Рособрнадзора. Все данные вносятся в ФИС ФРДО.'],
    ['q' => 'Увижу ли я документ на Госуслугах?', 'a' => 'Да. Данные вносятся в ФИС ФРДО в течение 30 дней после завершения обучения. После этого вы сможете увидеть запись в личном кабинете на Госуслугах.'],
    ['q' => 'Когда можно начать обучение?', 'a' => 'Методист открывает доступ к материалам в течение одного рабочего дня после оплаты. Дальше материалы доступны онлайн 24/7 — учитесь в своём темпе.'],
    ['q' => 'Сколько длится обучение на курсе «{product}»?', 'a' => 'Продолжительность зависит от объёма программы. Жёстких дедлайнов нет — вы проходите материалы в удобном темпе, а при необходимости доступ можно продлить.'],
    ['q' => 'Можно ли оплатить курс «{product}» от организации?', 'a' => 'Да. Для юридических лиц и образовательных организаций оформляем договор и акт, работаем по счёту и безналичному расчёту. Доступна рассрочка для физических лиц.'],
    ['q' => 'Нужно ли где-то присутствовать очно?', 'a' => 'Нет. Обучение и итоговая аттестация проходят дистанционно — присутствие в аудитории не требуется, учитесь из любого региона.'],
];
$faqItems = buildLandingFaq($faqPool, 'course:' . (int)$course['id'], ['product' => $course['title'] ?? ''], 5);
// Отзывы продукта + микроразметка рейтинга (aggregateRating/review)
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'course';
$reviewEntityId   = (int)$course['id'];
$reviewEntityName = $course['title'] ?? '';
$reviewObj   = new Review($db);
$reviewStats = $reviewObj->getStats($reviewEntityType, $reviewEntityId);
$reviewList  = $reviewObj->getApproved($reviewEntityType, $reviewEntityId, 20);
require_once __DIR__ . '/../includes/rating-synthetic-helper.php';
$reviewSeedKey = $reviewEntityType . ':' . $reviewEntityId;
$jsonLd['sku'] = syntheticSku($reviewSeedKey);
$jsonLd = applyReviewSchema($jsonLd, $reviewStats, $reviewList, $reviewSeedKey);

$jsonLdArray = [$jsonLd, buildFaqJsonLd($faqItems)];

// Хлебные крошки
$programTypeUrlMap = defined('COURSE_TYPE_URL_MAP') ? COURSE_TYPE_URL_MAP : [];
$programTypeSlug = $programTypeUrlMap[$course['program_type']] ?? '';
$programTypeLabel = Course::getProgramTypeLabel($course['program_type']);

$rdActivePage = 'kursy';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/course-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/course-detail.css'),
    '/assets/css/reviews.css?v=' . filemtime(__DIR__ . '/../assets/css/reviews.css'),
];
$additionalJS = $additionalJS ?? [];
$additionalJS[] = '/assets/js/reviews.js?v=' . filemtime(__DIR__ . '/../assets/js/reviews.js');

include __DIR__ . '/../includes/header-redesign.php';

$priceFormatted = number_format($abPrice, 0, ',', ' ');
$basePriceFormatted = number_format($abBasePrice, 0, ',', ' ');
$installment = calculateInstallment($abPrice);
?>

<main>

<!-- HERO -->
<section class="cd-hero">
  <div class="rd-wrap">
    <div class="cd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/kursy">Курсы</a>
      <?php if ($programTypeSlug): ?>
        <span class="sep">/</span>
        <a href="/kursy/<?php echo $programTypeSlug; ?>/"><?php echo htmlspecialchars($programTypeLabel); ?></a>
      <?php endif; ?>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars($course['title']); ?></strong>
    </div>

    <div class="cd-hero-grid">
      <div class="cd-hero-content">
        <div class="rd-pill-row reveal-stagger">
          <span class="rd-pill rd-pill-program"><?php echo htmlspecialchars($programShortLabel); ?></span>
          <span class="rd-pill indigo"><?php echo htmlspecialchars(Course::formatHours($course['hours'])); ?></span>
          <?php
          // Аудитория: только самый узкий уровень (специализация → тип → категория),
          // чтобы не плодить пилюли в шапке.
          $audiencePill = null;
          if (!empty($specializations[0]['name'])) {
              $audiencePill = $specializations[0]['name'];
          } elseif (!empty($audienceTypes[0]['name'])) {
              $audiencePill = $audienceTypes[0]['name'];
          } elseif (!empty($audienceCategories[0]['name'])) {
              $audiencePill = 'Для ' . mb_strtolower($audienceCategories[0]['name']);
          }
          if ($audiencePill):
          ?>
            <span class="rd-pill"><span class="dot"></span><?php echo htmlspecialchars($audiencePill); ?></span>
          <?php endif; ?>
          <span class="rd-pill">Дистанционно</span>
        </div>

        <h1 class="cd-hero-title reveal"><?php echo htmlspecialchars($course['title']); ?></h1>

        <div class="cd-hero-bullets reveal-stagger">
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span><?php echo htmlspecialchars($credentialType); ?></div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Запись в ФИС ФРДО — видно на Госуслугах</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Старт обучения сразу после оплаты</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Дистанционно из любой точки России</div>
        </div>

        <div class="cd-hero-cta reveal">
          <button type="button" class="rd-btn rd-btn-primary" onclick="openEnrollmentModal()">
            Записаться на курс
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </button>
          <button type="button" class="rd-btn rd-btn-ghost" onclick="openConsultationModal()">
            Получить консультацию
          </button>
        </div>

        <div class="cd-frdo-badge">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
          <span>Вносится в ФИС ФРДО — видно на Госуслугах</span>
        </div>
      </div>

      <!-- Hero art: разрешение Сколково -->
      <div class="cd-skolkovo-art reveal" onclick="openSkolkovoModal()">
        <div class="cd-skolkovo-frame">
          <img src="/assets/images/razreshenie-skolkovo-068.png"
               alt="Разрешение Сколково № 068 на образовательную деятельность"
               loading="eager">
        </div>
        <div class="cd-skolkovo-caption">Разрешение Сколково № 068 — нажмите, чтобы увеличить</div>
      </div>
    </div>
  </div>
</section>

<!-- BENEFITS -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-benefits-grid reveal-stagger">
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg></div>
        <h3>В ФИС ФРДО</h3>
        <p>Запись о <?php echo mb_strtolower($credentialShort); ?> вносится в Федеральный реестр и видна на Госуслугах.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg></div>
        <h3>Сколково № 068</h3>
        <p>Разрешение Фонда «Сколково» по 66 программам — таких организаций в России единицы.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></div>
        <h3><?php echo $credentialShort; ?> установленного образца</h3>
        <p>Документ принимается при аттестации и проверках Рособрнадзора.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
        <h3>Старт сразу после оплаты</h3>
        <p>Доступ к учебным материалам открывается автоматически — без ожидания.</p>
      </div>
    </div>
  </div>
</section>

<!-- О КУРСЕ + ДЛЯ КОГО -->
<?php if (!empty($course['description']) || !empty($course['target_audience_text'])): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">О курсе</div>
        <h2 class="rd-section-title">Для кого этот курс и чему он учит</h2>
      </div>
    </div>

    <div class="cd-about-grid">
      <div class="reveal">
        <div class="rd-prose">
          <?php if (!empty($course['description'])): ?>
            <?php foreach (explode("\n", $course['description']) as $paragraph): ?>
              <?php if (trim($paragraph)): ?>
                <p><?php echo htmlspecialchars(trim($paragraph)); ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>

          <?php if (!empty($course['target_audience_text'])): ?>
            <h3 style="font:700 19px var(--font-sans);color:var(--ink-900);margin:28px 0 14px;">Для кого этот курс</h3>
            <?php foreach (explode("\n", $course['target_audience_text']) as $paragraph): ?>
              <?php if (trim($paragraph)): ?>
                <p><?php echo htmlspecialchars(trim($paragraph)); ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="cd-info-cards reveal-stagger">
        <div class="cd-info-card cd-i-noms">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg></div>
            <h3>Объём программы</h3>
          </div>
          <div class="body" style="font:700 22px var(--font-sans);color:var(--ink-900);"><?php echo htmlspecialchars(Course::formatHours($course['hours'])); ?></div>
        </div>

        <div class="cd-info-card cd-i-awards">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg></div>
            <h3>Тип программы</h3>
          </div>
          <div class="body"><?php echo htmlspecialchars($programTypeLabel); ?></div>
        </div>

        <div class="cd-info-card cd-i-year">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3"/></svg></div>
            <h3>Форма обучения</h3>
          </div>
          <div class="body">Дистанционная, в удобном темпе</div>
        </div>

        <div class="cd-info-card cd-i-price <?php echo $installment['available'] ? 'cd-i-price-installment' : ''; ?>">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21V3h5a4 4 0 0 1 0 8H6"/><path d="M6 15h8"/></svg></div>
            <h3>Стоимость</h3>
          </div>
          <?php if ($installment['available']): ?>
            <div class="cd-price-monthly">
              <span class="cd-price-monthly-from">от</span>
              <span class="cd-price-monthly-amount"><?php echo formatRub($installment['monthly']); ?></span>
              <span class="cd-price-monthly-period">/мес</span>
            </div>
            <div class="cd-price-monthly-tag">
              <span class="installment-pill">0%</span>
              <span>рассрочка на <?php echo $installment['months']; ?> месяцев</span>
            </div>
            <div class="cd-price-full-line">
              <?php if ($hasDiscount): ?>
                <span class="cd-price-old"><?php echo $basePriceFormatted; ?> ₽</span>
              <?php endif; ?>
              <span class="cd-price-now"><?php echo $priceFormatted; ?> ₽</span>
              <?php if ($hasDiscount): ?>
                <span class="cd-price-discount-badge">−<?php echo $discountPercent; ?>%</span>
              <?php endif; ?>
              <span class="cd-price-full-label">или единоразово</span>
            </div>
            <div class="price-note">Оформляется в личном кабинете после записи</div>
          <?php else: ?>
            <div class="price-row">
              <?php echo $priceFormatted; ?> ₽
              <?php if ($hasDiscount): ?>
                <span style="display:inline-block;font:600 11px var(--font-sans);background:#ff4d6d;color:#fff;padding:3px 10px;border-radius:999px;margin-left:6px;vertical-align:middle;letter-spacing:.02em;">−<?php echo $discountPercent; ?>%</span>
              <?php endif; ?>
            </div>
            <?php if ($hasDiscount): ?>
              <div class="price-note" style="text-decoration:line-through;color:var(--ink-500);"><?php echo $basePriceFormatted; ?> ₽ — обычная цена</div>
            <?php else: ?>
              <div class="price-note">Без скрытых платежей</div>
            <?php endif; ?>
          <?php endif; ?>

          <div class="cd-urgency">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
            <span>Запишитесь сейчас — дополнительная скидка <b>−10%</b> действует <b>10 минут</b> после записи в личном кабинете.</span>
          </div>
        </div>

        <div style="grid-column:1 / -1;">
          <button type="button" class="rd-btn rd-btn-primary" onclick="openEnrollmentModal()" style="width:100%;justify-content:center;">
            Записаться на курс
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<?php include __DIR__ . '/../includes/social-proof.php'; ?>

<!-- ПРОГРАММА КУРСА -->
<?php if (!empty($modules)): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Программа</div>
        <h2 class="rd-section-title">Программа курса</h2>
      </div>
      <p class="rd-section-sub">
        <?php
        echo count($modules) . ' ';
        $mc = count($modules) % 10;
        $mc100 = count($modules) % 100;
        echo ($mc100 >= 11 && $mc100 <= 19) ? 'модулей' : ($mc == 1 ? 'модуль' : ($mc >= 2 && $mc <= 4 ? 'модуля' : 'модулей'));
        ?> в программе обучения
      </p>
    </div>
    <div class="cd-modules-grid reveal-stagger">
      <?php foreach ($modules as $module): ?>
      <div class="cd-module">
        <div class="n"><?php echo (int)$module['number']; ?></div>
        <p><?php echo htmlspecialchars($module['title']); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- БОЛЬШОЙ ЦЕНОВОЙ БЛОК -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-course-price <?php echo $installment['available'] ? 'cd-course-price-installment' : ''; ?> reveal">
      <?php if ($installment['available']): ?>
        <div class="label">Рассрочка 0% на <?php echo $installment['months']; ?> месяцев</div>
        <div class="amount">
          <span class="cd-big-from">от</span>
          <span><?php echo formatRub($installment['monthly']); ?></span>
          <span class="cd-big-period">/мес</span>
        </div>
        <div class="note">Без переплат и процентов · оформляется в личном кабинете после записи на курс</div>

        <div class="cd-full-price-line">
          <span class="cd-full-price-label">Полная стоимость:</span>
          <?php if ($hasDiscount): ?>
            <span class="cd-full-price-old"><?php echo $basePriceFormatted; ?> ₽</span>
          <?php endif; ?>
          <span class="cd-full-price-now"><?php echo $priceFormatted; ?> ₽</span>
          <?php if ($hasDiscount): ?>
            <span class="cd-full-price-badge">−<?php echo $discountPercent; ?>%</span>
          <?php endif; ?>
        </div>
        <div class="cd-full-price-meta"><?php echo htmlspecialchars(Course::formatHours($course['hours'])); ?> обучения с <?php echo mb_strtolower($credentialType); ?></div>
      <?php else: ?>
        <div class="label">Стоимость обучения</div>
        <div class="amount">
          <?php if ($hasDiscount): ?>
            <span class="amount-old"><?php echo $basePriceFormatted; ?> ₽</span>
          <?php endif; ?>
          <span><?php echo $priceFormatted; ?> ₽</span>
          <?php if ($hasDiscount): ?>
            <span class="amount-badge">−<?php echo $discountPercent; ?>%</span>
          <?php endif; ?>
        </div>
        <div class="note"><?php echo htmlspecialchars(Course::formatHours($course['hours'])); ?> обучения с <?php echo mb_strtolower($credentialType); ?></div>
      <?php endif; ?>

      <button type="button" class="rd-btn rd-btn-primary" onclick="openEnrollmentModal()">
        Оплатить курс
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </button>

      <div class="cd-course-price-features">
        <div class="feat">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          Дистанционно
        </div>
        <div class="feat">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          С <?php echo mb_strtolower($credentialShort); ?>
        </div>
        <div class="feat">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
          В ФИС ФРДО и на Госуслугах
        </div>
      </div>

      <div class="cd-course-price-guarantees">
        <div class="g">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          <span>Без скрытых платежей — итоговая цена на странице</span>
        </div>
        <div class="g">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          <span>Оплата по счёту для юридических лиц</span>
        </div>
        <div class="g">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          <span>Начало обучения сразу после оплаты</span>
        </div>
        <div class="g">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>
          <span>Возврат средств при отмене обучения</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ПРЕПОДАВАТЕЛИ -->
<?php if (!empty($experts)): ?>
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Преподаватели</div>
        <h2 class="rd-section-title">Преподаватели курса</h2>
      </div>
      <p class="rd-section-sub">Опытные эксперты-практики с многолетним стажем.</p>
    </div>
    <div class="cd-experts-grid reveal-stagger">
      <?php foreach ($experts as $expert): ?>
      <div class="cd-expert">
        <div class="photo">
          <img src="<?php echo htmlspecialchars($expert['photo_url'] ?: '/assets/images/experts/placeholder.svg'); ?>"
               alt="<?php echo htmlspecialchars($expert['full_name']); ?>"
               loading="lazy">
        </div>
        <div class="name"><?php echo htmlspecialchars($expert['full_name']); ?></div>
        <?php if (!empty($expert['credentials'])): ?>
          <div class="credentials"><?php echo htmlspecialchars($expert['credentials']); ?></div>
        <?php endif; ?>
        <?php if (!empty($expert['experience'])): ?>
          <div class="experience">Стаж: <?php echo htmlspecialchars($expert['experience']); ?></div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- РЕЗУЛЬТАТЫ ОБУЧЕНИЯ -->
<?php if (!empty($outcomes) && (!empty($outcomes['knowledge']) || !empty($outcomes['skills']) || !empty($outcomes['abilities']))): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Результаты</span>
      <h2 class="rd-section-title">Что вы получите после курса</h2>
    </div>
    <?php
    $renderOutcomeItems = function(array $items): void {
        foreach ($items as $raw) {
            $raw = trim((string)$raw);
            if ($raw === '') continue;
            if (strpos($raw, "\n") !== false) {
                $parts = preg_split('/\n+/u', $raw);
            } elseif (mb_strlen($raw) > 220) {
                $parts = preg_split('/(?<=[\.!?])\s+(?=[А-ЯA-Z])/u', $raw);
            } else {
                $parts = [$raw];
            }
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p === '') continue;
                echo '<li>' . htmlspecialchars($p, ENT_QUOTES, 'UTF-8') . '</li>';
            }
        }
    };
    ?>
    <div class="cd-outcomes-grid reveal-stagger">
      <?php if (!empty($outcomes['knowledge'])): ?>
      <div class="cd-outcome">
        <h3>Знания</h3>
        <ul><?php $renderOutcomeItems($outcomes['knowledge']); ?></ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($outcomes['skills'])): ?>
      <div class="cd-outcome">
        <h3>Умения</h3>
        <ul><?php $renderOutcomeItems($outcomes['skills']); ?></ul>
      </div>
      <?php endif; ?>

      <?php if (!empty($outcomes['abilities'])): ?>
      <div class="cd-outcome">
        <h3>Навыки</h3>
        <ul><?php $renderOutcomeItems($outcomes['abilities']); ?></ul>
      </div>
      <?php endif; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- CTA-консультация -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-consult reveal">
      <div class="cd-consult-text">
        <h3>Нужна помощь с выбором?</h3>
        <p>Оставьте номер — бесплатно проконсультируем по программе курса.</p>
      </div>
      <form class="cd-consult-form" id="consultationInlineForm" onsubmit="submitConsultationInline(event)">
        <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
        <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>">
        <input type="tel" name="phone" placeholder="+7 (___) ___-__-__" required>
        <button type="submit" class="rd-btn rd-btn-primary">Перезвоните мне</button>
      </form>
      <div class="cd-consult-success" id="consultationInlineSuccess">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
        <span>Заявка отправлена! Перезвоним в ближайшее время.</span>
      </div>
    </div>
  </div>
</section>

<!-- ФЕДЕРАЛЬНЫЙ РЕЕСТР -->
<?php if (!empty($course['federal_registry_info'])): ?>
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-frdo-card reveal">
      <h3>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        Федеральный реестр
      </h3>
      <p><?php echo htmlspecialchars($course['federal_registry_info']); ?></p>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- КАК ЗАПИСАТЬСЯ (4 шага) -->
<section class="rd-section rd-path">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Как пройти обучение</span>
      <h2 class="rd-section-title">Всего 4 шага до <?php echo mb_strtolower($credentialShort); ?>а</h2>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите курс</h4>
        <p>Ознакомьтесь с программой и убедитесь, что курс подходит вам.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Подайте заявку</h4>
        <p>Заполните форму на этой странице — ФИО, email и телефон.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Оплатите обучение</h4>
        <p>Оплата картой, СБП или по счёту для юридических лиц.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получите <?php echo mb_strtolower($credentialShort); ?></h4>
        <p>Пройдите обучение и получите документ установленного образца.</p>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Вопросы</span>
      <h2 class="rd-section-title">Вопросы и ответы</h2>
    </div>
    <?php renderFaqList($faqItems, 'reveal-stagger', 'style="max-width:880px;margin:0 auto;"'); ?>
  </div>
</section>

</main>

<!-- Фиксированный CTA (мобильный + десктопный sticky-бар) -->
<div class="cd-mobile-cta" id="cdMobileCta">
  <div class="cd-sticky-info">
    <span class="cd-sticky-title"><?php echo htmlspecialchars(mb_substr($course['title'], 0, 64)); ?></span>
    <span class="cd-sticky-price">
      <?php echo $priceFormatted; ?> ₽
      <?php if ($hasDiscount): ?><span class="cd-sticky-old"><?php echo $basePriceFormatted; ?> ₽</span><?php endif; ?>
    </span>
  </div>
  <button type="button" class="rd-btn rd-btn-primary" onclick="openEnrollmentModal()">
    Записаться на курс
  </button>
</div>

<!-- Enrollment Modal -->
<div class="cd-form-modal" id="enrollmentModal">
  <div class="modal-box">
    <button class="close-modal" onclick="closeEnrollmentModal()" aria-label="Закрыть">&times;</button>

    <div id="enrollmentForm">
      <h2>Запись на курс</h2>
      <p class="modal-subtitle"><?php echo htmlspecialchars(mb_substr($course['title'], 0, 80)); ?></p>

      <form onsubmit="submitEnrollment(event)" novalidate>
        <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
        <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>">

        <div class="form-group">
          <label for="enroll_name">ФИО</label>
          <input type="text" id="enroll_name" name="full_name" required placeholder="Иванова Мария Петровна">
          <div class="field-error" id="err_enroll_name"></div>
        </div>
        <div class="form-group">
          <label for="enroll_email">Email</label>
          <input type="email" id="enroll_email" name="email" required placeholder="ivanova@mail.ru">
          <div class="field-error" id="err_enroll_email"></div>
        </div>
        <div class="form-group">
          <label for="enroll_phone">Телефон</label>
          <input type="tel" id="enroll_phone" name="phone" required placeholder="+7 (___) ___-__-__">
          <div class="field-error" id="err_enroll_phone"></div>
        </div>

        <div class="form-agreement">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
            <input type="checkbox" name="agreement" required style="margin-top:3px;width:18px;height:18px;flex-shrink:0;">
            <span>
              Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank">Пользовательского соглашения</a>,
              <a href="/oferta-kursy/" target="_blank">Договора-оферты</a>
              и даю согласие на обработку персональных данных в соответствии с
              <a href="/politika-konfidencialnosti/" target="_blank">Политикой конфиденциальности</a>.
            </span>
          </label>
        </div>

        <button type="submit" class="btn-submit" id="enrollSubmitBtn">Перейти к оплате</button>
      </form>
    </div>

    <div class="form-success" id="enrollmentSuccess">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#22a55a" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
      <h3>Заявка отправлена!</h3>
      <p>Мы свяжемся с вами в ближайшее время для подтверждения записи на курс.</p>
      <button type="button" class="btn-submit" onclick="closeEnrollmentModal()">Закрыть</button>
    </div>
  </div>
</div>

<!-- Consultation Modal -->
<div class="cd-form-modal" id="consultationModal">
  <div class="modal-box">
    <button class="close-modal" onclick="closeConsultationModal()" aria-label="Закрыть">&times;</button>

    <div id="consultationForm">
      <div style="text-align:center;margin-bottom:18px;">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="var(--indigo-600)" stroke-width="1.5"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07 19.5 19.5 0 01-6-6 19.79 19.79 0 01-3.07-8.67A2 2 0 014.11 2h3a2 2 0 012 1.72 12.84 12.84 0 00.7 2.81 2 2 0 01-.45 2.11L8.09 9.91a16 16 0 006 6l1.27-1.27a2 2 0 012.11-.45 12.84 12.84 0 002.81.7A2 2 0 0122 16.92z"/></svg>
      </div>
      <h2 style="text-align:center;">Бесплатная консультация</h2>
      <p class="modal-subtitle" style="text-align:center;">Оставьте номер — мы перезвоним и ответим на вопросы о курсе.</p>

      <form onsubmit="submitConsultation(event)">
        <input type="hidden" name="course_id" value="<?php echo (int)$course['id']; ?>">
        <input type="hidden" name="course_title" value="<?php echo htmlspecialchars($course['title'], ENT_QUOTES); ?>">

        <div class="form-group">
          <label for="consult_phone">Телефон</label>
          <input type="tel" id="consult_phone" name="phone" required placeholder="+7 (___) ___-__-__">
        </div>

        <div class="form-agreement">
          <label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;">
            <input type="checkbox" name="agreement" required style="margin-top:3px;width:18px;height:18px;flex-shrink:0;">
            <span>
              Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank">Пользовательского соглашения</a>
              и даю согласие на обработку персональных данных в соответствии с
              <a href="/politika-konfidencialnosti/" target="_blank">Политикой конфиденциальности</a>.
            </span>
          </label>
        </div>

        <button type="submit" class="btn-submit" id="consultSubmitBtn">Перезвоните мне</button>
      </form>
    </div>

    <div class="form-success" id="consultationSuccess">
      <svg width="56" height="56" viewBox="0 0 24 24" fill="none" stroke="#22a55a" stroke-width="2"><path d="M9 12l2 2 4-4"/><circle cx="12" cy="12" r="10"/></svg>
      <h3>Заявка отправлена!</h3>
      <p>Мы перезвоним вам в ближайшее время.</p>
      <button type="button" class="btn-submit" onclick="closeConsultationModal()">Закрыть</button>
    </div>
  </div>
</div>

<!-- Skolkovo Modal -->
<div class="cd-skolkovo-modal" id="skolkovoModal">
  <div class="modal-box">
    <button class="close-modal" onclick="closeSkolkovoModal()" aria-label="Закрыть">&times;</button>
    <img src="/assets/images/razreshenie-skolkovo-068.png" alt="Разрешение Сколково № 068">
    <p>Разрешение № 068 от 16.03.2026 на осуществление образовательной деятельности.</p>
  </div>
</div>

<script>
// Modal helpers
function openEnrollmentModal() {
    document.getElementById('enrollmentModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeEnrollmentModal() {
    document.getElementById('enrollmentModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('enrollmentForm').style.display = '';
    document.getElementById('enrollmentSuccess').classList.remove('active');
}
function openConsultationModal() {
    document.getElementById('consultationModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeConsultationModal() {
    document.getElementById('consultationModal').classList.remove('active');
    document.body.style.overflow = '';
    document.getElementById('consultationForm').style.display = '';
    document.getElementById('consultationSuccess').classList.remove('active');
}
function openSkolkovoModal() {
    document.getElementById('skolkovoModal').classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeSkolkovoModal() {
    document.getElementById('skolkovoModal').classList.remove('active');
    document.body.style.overflow = '';
}

// Close on overlay click
['enrollmentModal', 'consultationModal', 'skolkovoModal'].forEach(function(id) {
    var el = document.getElementById(id);
    if (el) {
        el.addEventListener('click', function(e) {
            if (e.target === el) {
                if (id === 'enrollmentModal') closeEnrollmentModal();
                else if (id === 'consultationModal') closeConsultationModal();
                else closeSkolkovoModal();
            }
        });
    }
});

// Close on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeEnrollmentModal();
        closeConsultationModal();
        closeSkolkovoModal();
    }
});

// Phone mask +7 (___) ___-__-__
function formatPhone(digits) {
    var f = '';
    if (digits.length > 0) f = '+7';
    if (digits.length > 1) f += ' (' + digits.substring(1, 4);
    if (digits.length >= 4) f += ') ' + digits.substring(4, 7);
    if (digits.length >= 7) f += '-' + digits.substring(7, 9);
    if (digits.length >= 9) f += '-' + digits.substring(9, 11);
    return f;
}
function applyPhoneMask(input) {
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Backspace') {
            e.preventDefault();
            var digits = input.value.replace(/\D/g, '');
            if (digits.length <= 1) { input.value = '+7'; return; }
            digits = digits.substring(0, digits.length - 1);
            input.value = formatPhone(digits);
        }
    });
    input.addEventListener('input', function() {
        var digits = input.value.replace(/\D/g, '');
        if (digits.length > 0 && digits[0] === '8') digits = '7' + digits.substring(1);
        if (digits.length > 0 && digits[0] !== '7') digits = '7' + digits;
        if (digits.length > 11) digits = digits.substring(0, 11);
        input.value = formatPhone(digits);
    });
    input.addEventListener('focus', function() { if (!input.value) input.value = '+7'; });
    input.addEventListener('blur', function() { if (input.value === '+7') input.value = ''; });
}
document.querySelectorAll('input[type="tel"]').forEach(applyPhoneMask);

// Tracking helpers
function appendTrackingData(formData) {
    var urlParams = new URLSearchParams(window.location.search);
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function(key) {
        var val = urlParams.get(key) || sessionStorage.getItem('_fgos_' + key);
        if (val) formData.append(key, val);
    });
    var visitId = sessionStorage.getItem('_fgos_visit_id');
    if (visitId) formData.append('visit_id', visitId);
    var yclid = urlParams.get('yclid') || sessionStorage.getItem('_fgos_yclid');
    if (yclid) formData.append('yclid', yclid);
    var ymUid = document.cookie.match(/_ym_uid=(\d+)/);
    if (ymUid) formData.append('ym_uid', ymUid[1]);
    formData.append('source_page', window.location.pathname);
}

// Consultation submit (modal)
function submitConsultation(e) {
    e.preventDefault();
    var btn = document.getElementById('consultSubmitBtn');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(e.target);
    appendTrackingData(formData);

    fetch('/ajax/course-consultation.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'zayavkakurs'); }
                document.getElementById('consultationForm').style.display = 'none';
                document.getElementById('consultationSuccess').classList.add('active');
            } else {
                alert(data.message || 'Произошла ошибка. Попробуйте ещё раз.');
                btn.disabled = false;
                btn.textContent = 'Перезвоните мне';
            }
        })
        .catch(function() {
            alert('Произошла ошибка соединения. Попробуйте ещё раз.');
            btn.disabled = false;
            btn.textContent = 'Перезвоните мне';
        });
}

// Inline consultation submit (на странице)
function submitConsultationInline(e) {
    e.preventDefault();
    var form = e.target;
    var btn = form.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(form);
    appendTrackingData(formData);

    fetch('/ajax/course-consultation.php', { method: 'POST', body: formData })
        .then(function(res) { return res.json(); })
        .then(function(data) {
            if (data.success) {
                if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'zayavkakurs'); }
                form.style.display = 'none';
                var success = document.getElementById('consultationInlineSuccess');
                if (success) success.style.display = 'flex';
            } else {
                alert(data.message || 'Произошла ошибка. Попробуйте ещё раз.');
                btn.disabled = false;
                btn.textContent = 'Перезвоните мне';
            }
        })
        .catch(function() {
            alert('Произошла ошибка соединения. Попробуйте ещё раз.');
            btn.disabled = false;
            btn.textContent = 'Перезвоните мне';
        });
}

// Показ/сброс inline-ошибки поля
function setFieldError(inputId, message) {
    var input = document.getElementById(inputId);
    var box = document.getElementById('err_' + inputId);
    if (input) input.classList.toggle('has-error', !!message);
    if (box) { box.textContent = message || ''; box.classList.toggle('active', !!message); }
}
function clearFieldErrorOnInput(inputId) {
    var input = document.getElementById(inputId);
    if (input) input.addEventListener('input', function() { setFieldError(inputId, ''); });
}
['enroll_name', 'enroll_email', 'enroll_phone'].forEach(clearFieldErrorOnInput);

// Enrollment submit
function submitEnrollment(e) {
    e.preventDefault();
    var btn = document.getElementById('enrollSubmitBtn');
    var form = e.target;

    var name = form.querySelector('[name="full_name"]').value.trim();
    var email = form.querySelector('[name="email"]').value.trim();
    var phoneDigits = form.querySelector('[name="phone"]').value.replace(/\D/g, '');

    var ok = true;
    setFieldError('enroll_name', '');
    setFieldError('enroll_email', '');
    setFieldError('enroll_phone', '');
    if (!name) { setFieldError('enroll_name', 'Укажите ФИО'); ok = false; }
    if (!email) { setFieldError('enroll_email', 'Укажите email'); ok = false; }
    else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) { setFieldError('enroll_email', 'Введите корректный email'); ok = false; }
    if (phoneDigits.length !== 11) { setFieldError('enroll_phone', 'Введите телефон полностью'); ok = false; }
    if (!ok) {
        var firstErr = form.querySelector('.has-error');
        if (firstErr) firstErr.focus();
        return;
    }

    btn.disabled = true;
    btn.textContent = 'Отправка...';

    var formData = new FormData(form);
    appendTrackingData(formData);

    fetch('/ajax/course-enrollment.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(response) {
            if (!response.success) {
                alert(response.message || 'Произошла ошибка. Попробуйте ещё раз.');
                btn.disabled = false;
                btn.textContent = 'Перейти к оплате';
                return;
            }

            var redirectUrl = response.cabinet_url || '/kabinet/?tab=courses&enrolled=success';

            if (response.ecommerce) {
                window.dataLayer = window.dataLayer || [];
                window.dataLayer.push({
                    "ecommerce": {
                        "currencyCode": "RUB",
                        "add": {
                            "products": [{
                                "id": response.ecommerce.id,
                                "name": response.ecommerce.name,
                                "price": response.ecommerce.price,
                                "brand": "Педпортал",
                                "category": "Курсы",
                                "quantity": 1
                            }]
                        }
                    }
                });
            }

            var redirected = false;
            function doRedirect() {
                if (!redirected) { redirected = true; window.location.href = redirectUrl; }
            }
            if (typeof ym === 'function') {
                ym(106465857, 'reachGoal', 'zayavkakurs', null, doRedirect);
                setTimeout(doRedirect, 1000);
            } else {
                doRedirect();
            }
        })
        .catch(function() {
            window.location.href = '/kabinet/?tab=courses&enrolled=success';
        });
}

// Фиксированный CTA-бар (мобильный + десктопный): показываем после ухода hero-CTA из вида
(function() {
    var heroCta = document.querySelector('.cd-hero-cta');
    var fixedCta = document.getElementById('cdMobileCta');
    if (heroCta && fixedCta && 'IntersectionObserver' in window) {
        var obs = new IntersectionObserver(function(entries) {
            fixedCta.classList.toggle('visible', !entries[0].isIntersecting);
        }, { threshold: 0 });
        obs.observe(heroCta);
    }
})();
</script>

<!-- E-commerce: Detail -->
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "actionField": {"list": ""},
            "products": [{
                "id": "course-<?= (int)$course['id'] ?>",
                "name": "<?= htmlspecialchars($course['title'], ENT_QUOTES) ?>",
                "price": <?= $abPrice ?>,
                "brand": "Педпортал",
                "category": "Курсы"
            }]
        }
    }
});
if (typeof ym === 'function') ym(106465857, 'params', {course_discount: '<?= $discountPercent ?>'});
</script>

<?php include __DIR__ . '/../includes/review-section.php'; ?>

<?php include __DIR__ . '/../includes/social-links-redesign.php'; ?>

<?php
include __DIR__ . '/../includes/footer-redesign.php';
?>

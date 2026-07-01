<?php
/**
 * Competition Detail Page — редизайн
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/session.php';

$slug = $_GET['slug'] ?? '';
$audienceSlug = $_GET['audience'] ?? null;

if (empty($slug)) {
    header('Location: /konkursy');
    exit;
}

$competitionObj = new Competition($db);
$competition = $competitionObj->getBySlug($slug);

// Склейка дублей-тайтлов (SEO): проигравшая версия 301-редиректится на канонический слаг
if ($competition && !empty($competition['redirect_to_slug'])) {
    header('Location: /konkursy/' . urlencode($competition['redirect_to_slug']) . '/', true, 301);
    exit;
}

if (!$competition) {
    http_response_code(404);
    $pageTitle = 'Конкурс не найден | ' . SITE_NAME;
    $pageDescription = 'Запрашиваемый конкурс не найден';
    $noindex = true;
    $rdActivePage = 'konkursy';
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <main>
      <section class="rd-section">
        <div class="rd-wrap" style="text-align:center;">
          <h1 style="font:700 36px var(--font-sans);color:var(--ink-900);margin-bottom:12px;">Конкурс не найден</h1>
          <p style="color:var(--ink-500);margin-bottom:24px;">Возможно, он был удалён или перемещён.</p>
          <a href="/konkursy" class="rd-btn rd-btn-primary">Все конкурсы</a>
        </div>
      </section>
    </main>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$nominations = $competitionObj->getNominationOptions($competition['id']);
$audienceTypes = $competitionObj->getAudienceTypes($competition['id']);
$specializations = $competitionObj->getSpecializations($competition['id']);

$pageTitle = htmlspecialchars($competition['title']) . ' | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($competition['description'], 0, 150));
$canonicalUrl = SITE_URL . '/konkursy/' . $competition['slug'] . '/';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $competition['title'],
    'description' => mb_substr(strip_tags($competition['description']), 0, 300),
    'url' => SITE_URL . '/konkursy/' . $competition['slug'] . '/',
    'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
    'eventStatus' => 'https://schema.org/EventScheduled',
    'organizer' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL],
    'offers' => [
        '@type' => 'Offer',
        'price' => $competition['price'],
        'priceCurrency' => 'RUB',
        'availability' => 'https://schema.org/InStock',
        'refundPolicy' => [
            '@type' => 'RefundPolicy',
            'url' => SITE_URL . '/oferta-meropriyatiya/#refund'
        ]
    ]
];
$ogType = 'article';
$ogImage = SITE_URL . '/og-image/competition/' . $competition['slug'] . '.jpg';

$deadline = new DateTime();
$deadline->modify('+2 days');
$deadline_formatted = 'Приём документов до ' . $deadline->format('d.m.Y');

$rdActivePage = 'konkursy';
$additionalCSS = ['/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css')];

$registrationUrl = '/pages/registration.php?competition_id=' . (int)$competition['id'];
$groupRegistrationUrl = '/pages/group-registration.php?product_type=competition&product_id=' . (int)$competition['id'];

// FAQ-блок + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Как быстро я получу диплом?', 'a' => 'Диплом формируется автоматически сразу после подтверждения оплаты. Обычно это занимает не более 5 минут. Вы сможете скачать диплом в личном кабинете в формате PDF.'],
    ['q' => 'Как можно оплатить?', 'a' => 'Мы принимаем оплату через ЮKassa: банковские карты (Visa, MasterCard, МИР), электронные кошельки (ЮMoney, QIWI), СБП. Все платежи защищены и проходят через безопасное соединение.'],
    ['q' => 'Можно ли изменить данные в дипломе?', 'a' => 'Да, вы можете обратиться в службу поддержки для корректировки данных в дипломе. Мы бесплатно исправим любые ошибки и вышлем обновлённый диплом.'],
    ['q' => 'Действует ли скидка на несколько конкурсов?', 'a' => 'Да! При оплате двух конкурсов третий конкурс вы получаете бесплатно. Добавьте конкурсы в корзину и оплатите все сразу — скидка применяется автоматически.'],
    ['q' => 'Нужна ли регистрация на сайте?', 'a' => 'Регистрация происходит автоматически при оформлении участия в конкурсе. Вы получите доступ в личный кабинет, где сможете управлять дипломами.'],
    ['q' => 'Сколько хранятся дипломы на сайте?', 'a' => 'Дипломы хранятся в вашем личном кабинете бессрочно. Вы можете скачать их в любой момент.'],
    ['q' => 'Вы выдаёте официальные дипломы?', 'a' => 'Да, все наши дипломы являются официальными документами. Мы работаем на основании свидетельства о регистрации СМИ: Эл. №ФС 77-74524.'],
    ['q' => 'Можно ли выбрать дизайн диплома?', 'a' => 'Да, при оформлении участия вы можете выбрать один из предложенных шаблонов дизайна диплома.'],
];
// Отзывы продукта + микроразметка рейтинга (aggregateRating/review)
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'competition';
$reviewEntityId   = (int)$competition['id'];
$reviewObj   = new Review($db);
$reviewStats = $reviewObj->getStats($reviewEntityType, $reviewEntityId);
$reviewList  = $reviewObj->getApproved($reviewEntityType, $reviewEntityId, 20);
require_once __DIR__ . '/../includes/rating-synthetic-helper.php';
$reviewSeedKey = $reviewEntityType . ':' . $reviewEntityId;
$jsonLd['image'] = $ogImage;
$jsonLd['sku'] = syntheticSku($reviewSeedKey);
$jsonLd = applyReviewSchema($jsonLd, $reviewStats, $reviewList, $reviewSeedKey);
$additionalCSS[] = '/assets/css/reviews.css?v=' . filemtime(__DIR__ . '/../assets/css/reviews.css');
$additionalJS = $additionalJS ?? [];
$additionalJS[] = '/assets/js/reviews.js?v=' . filemtime(__DIR__ . '/../assets/js/reviews.js');

$jsonLdArray = [$jsonLd, buildFaqJsonLd($faqItems)];

include __DIR__ . '/../includes/header-redesign.php';

$priceFormatted = number_format($competition['price'], 0, ',', ' ');

// A/B-тест: в варианте B (не-подписчик) поштучной цены нет — диплом доступен только по подписке.
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
$pmUserId = $_SESSION['user_id'] ?? null;
$pmIsSubscriber = $pmUserId ? (new SubscriptionService($db))->coversCertificates((int)$pmUserId) : false;
$pmSubscriptionOnly = PricingMode::isSubscriptionOnly() && !$pmIsSubscriber;
?>

<main>

<!-- HERO -->
<section class="cd-hero">
  <div class="rd-wrap">
    <div class="cd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/konkursy">Конкурсы</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars($competition['title']); ?></strong>
    </div>

    <div class="cd-hero-grid">
      <div class="cd-hero-content">
        <div class="rd-pill-row reveal-stagger">
          <span class="rd-pill"><span class="dot"></span>Конкурс для <?php echo htmlspecialchars($competition['target_participants_genitive'] ?? $competition['target_participants']); ?></span>
          <span class="rd-pill indigo"><?php echo htmlspecialchars($deadline_formatted); ?></span>
        </div>

        <h1 class="cd-hero-title reveal"><?php echo htmlspecialchars($competition['title']); ?></h1>

        <div class="cd-hero-bullets reveal-stagger">
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Дистанционный формат</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Одноэтапный</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Диплом сразу после оплаты</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Соответствует ФГОС</div>
        </div>

        <div class="cd-hero-cta reveal">
          <a href="<?php echo $registrationUrl; ?>" class="rd-btn rd-btn-primary">
            Принять участие
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
          <a href="<?php echo $groupRegistrationUrl; ?>" class="rd-btn rd-btn-ghost cd-group-cta">
            Оформить на группу / весь класс
          </a>
          <div class="cd-skolkovo">
            <img src="/assets/images/skolkovo.webp" alt="Резидент Сколково">
            <span>Резидент<br>Сколково</span>
          </div>
        </div>
      </div>

      <!-- ВЕЕР ДИПЛОМОВ — НЕ МЕНЯТЬ -->
      <div class="cd-hero-art reveal">
        <div class="hero-diploma">
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
      </div>
    </div>
  </div>
</section>

<!-- BENEFITS -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-benefits-grid reveal-stagger">
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/></svg></div>
        <h3>Дистанционный формат</h3>
        <p>Участвуйте из любой точки России без необходимости выезда.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
        <h3>Быстрый результат</h3>
        <p>Получите диплом сразу после оплаты — без ожидания.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg></div>
        <h3>Официальный документ</h3>
        <p>Диплом от издания с регистрацией СМИ для портфолио.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 12 20 22 4 22 4 12"/><rect x="2" y="7" width="20" height="5"/><line x1="12" y1="22" x2="12" y2="7"/><path d="M12 7H7.5a2.5 2.5 0 0 1 0-5C11 2 12 7 12 7z"/><path d="M12 7h4.5a2.5 2.5 0 0 0 0-5C13 2 12 7 12 7z"/></svg></div>
        <h3>Акция 2+1</h3>
        <p>При оплате двух конкурсов третий — бесплатно.</p>
      </div>
    </div>
  </div>
</section>

<!-- О КОНКУРСЕ -->
<?php if (!empty($competition['description'])): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">О конкурсе</span>
      <h2 class="rd-section-title">Что нужно знать перед участием</h2>
    </div>

    <div class="cd-about-grid">
      <div class="reveal">
        <div class="rd-prose">
          <?php
          $displayDescription = !empty($competition['seo_description']) ? $competition['seo_description'] : $competition['description'];
          $paragraphs = explode("\n\n", $displayDescription);
          foreach ($paragraphs as $paragraph):
              if (empty(trim($paragraph))) continue;
          ?>
            <p><?php echo nl2br(htmlspecialchars($paragraph)); ?></p>
          <?php endforeach; ?>
        </div>
        <div class="cd-about-actions">
          <button type="button" class="rd-btn rd-btn-ghost"
                  onclick="openRegulationsModal('<?php echo (int)$competition['id']; ?>', '<?php echo htmlspecialchars($competition['title'], ENT_QUOTES); ?>')">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="9" y1="13" x2="15" y2="13"/><line x1="9" y1="17" x2="13" y2="17"/></svg>
            Положение конкурса
          </button>
        </div>
      </div>

      <div class="cd-info-cards reveal-stagger">
        <?php if (!empty($nominations)): ?>
        <div class="cd-info-card cd-i-noms">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg></div>
            <h3>Номинации</h3>
          </div>
          <ul>
            <?php
            $displayCount = min(count($nominations), 4);
            for ($i = 0; $i < $displayCount; $i++):
            ?>
              <li><?php echo htmlspecialchars($nominations[$i]); ?></li>
            <?php endfor; ?>
            <?php if (count($nominations) > 4): ?>
              <li class="more">и ещё <?php echo count($nominations) - 4; ?>...</li>
            <?php endif; ?>
          </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($competition['award_structure'])): ?>
        <div class="cd-info-card cd-i-awards">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></div>
            <h3>Награды</h3>
          </div>
          <div class="body"><?php echo nl2br(htmlspecialchars($competition['award_structure'])); ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($competition['academic_year'])): ?>
        <div class="cd-info-card cd-i-year">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <h3>Учебный год</h3>
          </div>
          <div class="body"><?php echo htmlspecialchars($competition['academic_year']); ?></div>
        </div>
        <?php endif; ?>

        <div class="cd-info-card cd-i-price">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21V3h5a4 4 0 0 1 0 8H6"/><path d="M6 15h8"/></svg></div>
            <h3>Стоимость участия</h3>
          </div>
          <?php if ($pmSubscriptionOnly): ?>
          <div class="price-row">По подписке</div>
          <div class="price-note">Диплом входит в подписку для портфолио — без поштучной оплаты</div>
          <?php else: ?>
          <div class="price-row"><?php echo $priceFormatted; ?> ₽</div>
          <div class="price-note">Третий конкурс — бесплатно при оплате двух</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ЦЕНОВОЙ CTA-БАННЕР -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="cd-price-band reveal">
      <?php if ($pmSubscriptionOnly): ?>
      <div class="label">Оформление диплома</div>
      <div class="amount">По подписке</div>
      <div class="note">Диплом конкурса входит в подписку для портфолио — оформляется без поштучной оплаты.</div>
      <?php else: ?>
      <div class="label">Стоимость участия</div>
      <div class="amount"><?php echo $priceFormatted; ?> ₽</div>
      <div class="note">При оплате двух конкурсов третий — бесплатно. Для группы — скидка до 30%.</div>
      <?php endif; ?>
      <a href="<?php echo $registrationUrl; ?>" class="rd-btn rd-btn-primary">
        Принять участие
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </a>
      <a href="<?php echo $groupRegistrationUrl; ?>" class="rd-btn rd-btn-ghost cd-group-cta">
        Оформить на группу / весь класс
      </a>
    </div>
  </div>
</section>

<!-- ЦЕЛИ -->
<?php if (!empty($competition['goals'])): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Цели</div>
        <h2 class="rd-section-title">Цели конкурса</h2>
      </div>
      <p class="rd-section-sub">Конкурс направлен на достижение следующих целей.</p>
    </div>
    <div class="cd-goals-grid reveal-stagger">
      <?php
      $goals = explode("\n", $competition['goals']);
      $goalIcons = ['🎯', '🌟', '📈', '🏆', '💡'];
      $goalIdx = 0;
      foreach ($goals as $goal):
          if (empty(trim($goal))) continue;
          $icon = $goalIcons[$goalIdx % count($goalIcons)];
          $goalIdx++;
      ?>
      <div class="cd-goal">
        <div class="ic"><?php echo $icon; ?></div>
        <p><?php echo htmlspecialchars(trim($goal)); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ЗАДАЧИ -->
<?php if (!empty($competition['objectives'])): ?>
<section class="rd-section tight" style="background:var(--ink-50);">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Задачи</div>
        <h2 class="rd-section-title">Задачи конкурса</h2>
      </div>
      <p class="rd-section-sub">Для достижения поставленных целей решаются следующие задачи.</p>
    </div>
    <div class="cd-objectives reveal-stagger">
      <?php
      $objectives = explode("\n", $competition['objectives']);
      $objIdx = 0;
      foreach ($objectives as $objective):
          if (empty(trim($objective))) continue;
          $objIdx++;
      ?>
      <div class="cd-objective">
        <div class="n"><?php echo $objIdx; ?></div>
        <p><?php echo htmlspecialchars(trim($objective)); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- НОМИНАЦИИ -->
<?php if (!empty($nominations)): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Номинации</div>
        <h2 class="rd-section-title">Номинации конкурса</h2>
      </div>
      <p class="rd-section-sub">Выберите одну из следующих номинаций при регистрации.</p>
    </div>
    <div class="cd-noms-grid reveal-stagger">
      <?php foreach ($nominations as $idx => $nomination): ?>
      <div class="cd-nom">
        <div class="n"><?php echo $idx + 1; ?></div>
        <p><?php echo htmlspecialchars($nomination); ?></p>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="cd-inline-cta reveal">
      <p>Выбрали подходящую номинацию?</p>
      <a href="<?php echo $registrationUrl; ?>" class="rd-btn rd-btn-primary">
        Выбрать номинацию и участвовать
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </a>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- КРИТЕРИИ ОЦЕНКИ -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Критерии</span>
      <h2 class="rd-section-title">Критерии оценки конкурсных работ</h2>
    </div>
    <div class="cd-criteria-grid reveal-stagger">
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg></div>
        <h4>Целесообразность материала</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18h6"/><path d="M10 22h4"/><path d="M12 2a7 7 0 0 0-7 7c0 2.38 1.19 4.47 3 5.74V17a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1v-2.26c1.81-1.27 3-3.36 3-5.74a7 7 0 0 0-7-7z"/></svg></div>
        <h4>Оригинальность материала</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/><line x1="8" y1="7" x2="16" y2="7"/><line x1="8" y1="11" x2="14" y2="11"/></svg></div>
        <h4>Полнота и информативность</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6v2H9z"/><path d="M10 5v4"/><path d="M14 5v4"/><circle cx="12" cy="14" r="5"/><path d="M12 12v2"/><path d="M12 16h.01"/></svg></div>
        <h4>Научная достоверность</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 19l7-7 3 3-7 7-3-3z"/><path d="M18 13l-1.5-7.5L2 2l3.5 14.5L13 18l5-5z"/><path d="M2 2l7.586 7.586"/><circle cx="11" cy="11" r="2"/></svg></div>
        <h4>Стиль и логичность изложения</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="13.5" cy="6.5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="12" r="2.5"/><circle cx="8.5" cy="18.5" r="2.5"/><circle cx="15.5" cy="18.5" r="2.5"/></svg></div>
        <h4>Качество оформления</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4.5 16.5c-1.5 1.26-2 5-2 5s3.74-.5 5-2c.71-.84.7-2.13-.09-2.91a2.18 2.18 0 0 0-2.91-.09z"/><path d="M12 15l-3-3a22 22 0 0 1 2-3.95A12.88 12.88 0 0 1 22 2c0 2.72-.78 7.5-6 11a22.35 22.35 0 0 1-4 2z"/><path d="M9 12H4s.55-3.03 2-4c1.62-1.08 5 0 5 0"/><path d="M12 15v5s3.03-.55 4-2c1.08-1.62 0-5 0-5"/></svg></div>
        <h4>Практическое применение</h4>
      </div>
      <div class="cd-criteria">
        <div class="ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg></div>
        <h4>Соответствие ФГОС</h4>
      </div>
    </div>
  </div>
</section>

<!-- КАК ПРИНЯТЬ УЧАСТИЕ -->
<section class="rd-section rd-path">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Как участвовать</span>
      <h2 class="rd-section-title">Всего 4 шага до получения диплома</h2>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Регистрация</h4>
        <p>Заполните форму и выберите дизайн диплома из предложенных вариантов.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Оплата</h4>
        <p>Оплата через ЮKassa: банковские карты, электронные кошельки, СБП.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Доступ к кабинету</h4>
        <p>Доступ к личному кабинету открывается автоматически сразу после оплаты.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Получение диплома</h4>
        <p>Скачайте диплом в формате PDF и используйте его в портфолио.</p>
      </div>
    </div>
  </div>
</section>

<!-- ТЁМНЫЙ CTA-БАННЕР -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal" style="text-align:center;">
      <div class="rd-trust-head" style="max-width:none;">
        <span class="rd-eyebrow">Готовы участвовать?</span>
        <h2 style="color:#fff;font:700 clamp(28px, 3vw, 40px) var(--font-sans);letter-spacing:-.02em;margin:0 0 12px;">Получите диплом сегодня</h2>
        <p style="color:#c2cdff;font-size:16px;line-height:1.55;margin:0 0 28px;">Заполните форму за 2 минуты и получите официальный диплом сразу после оплаты.</p>
        <a href="<?php echo $registrationUrl; ?>" class="rd-btn rd-btn-primary" style="background:#fff;color:var(--indigo-700);">
          Принять участие
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <div style="margin-top:24px;display:flex;gap:24px;justify-content:center;flex-wrap:wrap;color:#d6deff;font-size:14px;">
          <span>✓ Дистанционно</span>
          <span>✓ Диплом сразу</span>
          <span>✓ Акция 2+1</span>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Вопросы</span>
      <h2 class="rd-section-title">Вопросы и ответы</h2>
    </div>
    <?php renderFaqList($faqItems, 'reveal-stagger', 'style="max-width:880px;margin:0 auto;"'); ?>
  </div>
</section>

</main>

<!-- Фиксированная мобильная кнопка -->
<div class="cd-mobile-cta" id="cdMobileCta">
  <a href="<?php echo $registrationUrl; ?>" class="rd-btn rd-btn-primary">
    Принять участие
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
  </a>
</div>

<!-- Regulations Modal -->
<div id="regulationsModal" class="modal" style="display: none;">
  <div class="modal-overlay" onclick="closeRegulationsModal()"></div>
  <div class="modal-content">
    <div class="modal-header">
      <h2 id="regulationsModalTitle">Положение о конкурсе</h2>
      <button class="modal-close" onclick="closeRegulationsModal()" aria-label="Закрыть">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <div class="modal-body" id="regulationsModalBody"></div>
  </div>
</div>

<script>
(function(){
  // Mobile fixed CTA — показ после ухода hero за viewport
  if (window.innerWidth <= 768) {
    var heroCta = document.querySelector('.cd-hero-cta');
    var fixedCta = document.getElementById('cdMobileCta');
    if (heroCta && fixedCta) {
      var obs = new IntersectionObserver(function(entries){
        fixedCta.classList.toggle('visible', !entries[0].isIntersecting);
      }, { threshold: 0 });
      obs.observe(heroCta);
    }
  }
})();
</script>

<?php
$audienceTypeStmt = $db->prepare("
    SELECT at.name
    FROM audience_types at
    JOIN competition_audience_types cat ON at.id = cat.audience_type_id
    WHERE cat.competition_id = ?
    LIMIT 1
");
$audienceTypeStmt->execute([$competition['id']]);
$ecomAudienceType = $audienceTypeStmt->fetchColumn() ?: 'Общее';

$specializationStmt = $db->prepare("
    SELECT aspc.name
    FROM audience_specializations aspc
    JOIN competition_specializations cs ON aspc.id = cs.specialization_id
    WHERE cs.competition_id = ?
    LIMIT 1
");
$specializationStmt->execute([$competition['id']]);
$ecomSpecialization = $specializationStmt->fetchColumn() ?: '';
?>
<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "actionField": {"list": "<?php echo htmlspecialchars($ecomSpecialization, ENT_QUOTES); ?>"},
            "products": [{
                "id": "<?php echo (int)$competition['id']; ?>",
                "name": "<?php echo htmlspecialchars($competition['title'], ENT_QUOTES); ?>",
                "price": <?php echo (float)$competition['price']; ?>,
                "brand": "Педпортал",
                "category": "Конкурсы"
            }]
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/review-section.php'; ?>

<?php include __DIR__ . '/../includes/social-links.php'; ?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

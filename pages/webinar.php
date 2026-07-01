<?php
/**
 * Webinar Detail/Landing Page (/vebinar/<slug>/) — редизайн.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Webinar.php';
require_once __DIR__ . '/../classes/WebinarRegistration.php';
require_once __DIR__ . '/../includes/session.php';

initSession();

$database        = new Database($db);
$webinarObj      = new Webinar($db);
$registrationObj = new WebinarRegistration($db);

$slug = $_GET['slug'] ?? '';
if (empty($slug)) { header('Location: /vebinary/'); exit; }

$webinar = $webinarObj->getBySlug($slug);

if (!$webinar) {
    http_response_code(404);
    $pageTitle = 'Вебинар не найден | ' . SITE_NAME;
    include __DIR__ . '/../includes/header-redesign.php';
    echo '<section class="rd-section"><div class="rd-wrap" style="text-align:center;padding:80px 0;">'
       . '<h1 class="rd-hero-title rd-hero-title-sm">Вебинар не найден</h1>'
       . '<p class="rd-hero-sub" style="margin:16px auto 28px;">Возможно, он был удалён или перемещён.</p>'
       . '<a href="/vebinary/" class="rd-btn rd-btn-primary">Все вебинары</a>'
       . '</div></section>';
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$webinarObj->incrementViews($webinar['id']);
$audienceTypes = $webinarObj->getAudienceTypes($webinar['id']);

$isRegistered = false;
$userEmail = $_SESSION['user_email'] ?? '';
if ($userEmail) {
    $isRegistered = $registrationObj->isRegistered($webinar['id'], $userEmail);
}

$dateInfo      = Webinar::formatDateTime($webinar['scheduled_at']);
$isUpcoming    = in_array($webinar['status'], ['scheduled', 'live']);
$isAutowebinar = $webinar['status'] === 'videolecture';
$isFree        = !empty($webinar['is_free']);

// A/B-тест: в варианте B (не-подписчик) цены сертификата нет — он доступен только по подписке.
require_once __DIR__ . '/../classes/PricingMode.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
$pmUserId = $_SESSION['user_id'] ?? null;
$pmIsSubscriber = $pmUserId ? (new SubscriptionService($db))->coversCertificates((int)$pmUserId) : false;
$pmSubscriptionOnly = PricingMode::isSubscriptionOnly() && !$pmIsSubscriber;

$pageTitle       = ($webinar['meta_title'] ?: 'Вебинар: ' . $webinar['title']) . ' | ' . SITE_NAME;
$pageDescription = $webinar['meta_description'] ?: $webinar['short_description'];
$canonicalUrl    = SITE_URL . '/vebinar/' . $webinar['slug'] . '/';
$rdActivePage    = 'vebinary';
$additionalCSS   = [
    '/assets/css/webinar-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/webinar-detail.css'),
];

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Event',
    'name' => $webinar['title'],
    'description' => $webinar['short_description'] ?: mb_substr(strip_tags($webinar['description']), 0, 300),
    'url' => $canonicalUrl,
    'startDate' => $webinar['scheduled_at'],
    'eventAttendanceMode' => 'https://schema.org/OnlineEventAttendanceMode',
    'organizer' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL],
    'performer' => ['@type' => 'Person', 'name' => $webinar['speaker_name'] ?? '']
];
$ogImage = !empty($webinar['cover_image'])
    ? SITE_URL . $webinar['cover_image']
    : SITE_URL . '/og-image/webinar/' . $webinar['slug'] . '.jpg';
$ogType = 'article';

// FAQ-блок + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Как получить ссылку на ' . ($isAutowebinar ? 'видеолекцию' : 'вебинар') . '?',
     'a' => 'После регистрации ссылка придёт на email. ' . ($isAutowebinar ? 'Доступ — сразу.' : 'За 24 часа и за час до эфира пришлём напоминания.')],
    ['q' => 'Участие платное?',
     'a' => 'Нет, участие бесплатное. ' . ($pmSubscriptionOnly ? 'Именной сертификат участника для портфолио оформляется по подписке — без поштучной оплаты.' : ('Платный — только именной сертификат участника (' . number_format((float)$webinar['certificate_price'], 0, ',', ' ') . ' ₽).'))],
    ['q' => 'Будет ли запись?',
     'a' => 'Да, после эфира пришлём ссылку на запись и презентацию спикера.'],
    ['q' => 'Как получить сертификат?',
     'a' => 'После просмотра пройдите короткий тест из 5 вопросов и оформите именной сертификат на ' . (int)$webinar['certificate_hours'] . ' ак. ч.'],
];
if (!$isAutowebinar) {
    $faqItems[] = ['q' => 'Можно задать вопрос спикеру?',
        'a' => 'Да. В прямом эфире можно задавать вопросы в чате — спикер ответит на самые интересные в конце.'];
}
$faqItems[] = ['q' => 'Что нужно для участия?',
    'a' => 'Компьютер, планшет или смартфон с интернетом. Платформа работает в браузере — установка не нужна.'];
// Отзывы продукта + микроразметка рейтинга (aggregateRating/review)
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'webinar';
$reviewEntityId   = (int)$webinar['id'];
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
?>

<!-- HERO деталки -->
<section class="rd-hero">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/vebinary/">Вебинары</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars(mb_substr($webinar['title'], 0, 60)); ?></strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <?php if ($isAutowebinar): ?>
          <span class="rd-pill indigo"><span class="dot"></span>Видеолекция · 24/7</span>
        <?php else: ?>
          <span class="rd-pill indigo"><span class="dot"></span><?php echo htmlspecialchars($dateInfo['date_full'] ?? $dateInfo['date']); ?> · <?php echo htmlspecialchars($dateInfo['time']); ?> МСК</span>
        <?php endif; ?>
        <?php if ($isFree): ?><span class="rd-pill">Бесплатно</span><?php endif; ?>
        <span class="rd-pill">Сертификат на <?php echo (int)($webinar['certificate_hours'] ?? 2); ?> ак. ч.</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal"><?php echo htmlspecialchars($webinar['title']); ?></h1>
      <?php if (!empty($webinar['short_description'])): ?>
        <p class="rd-hero-sub reveal"><?php echo htmlspecialchars($webinar['short_description']); ?></p>
      <?php endif; ?>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span><?php echo $isAutowebinar ? 'Доступ к записи сразу после регистрации' : 'Прямой эфир с возможностью задать вопрос'; ?></div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Презентация и материалы спикера</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Запись эфира — навсегда в личном кабинете</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Именной сертификат участника</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="#registration-form" class="rd-btn rd-btn-primary"><?php echo $isAutowebinar ? 'Получить доступ' : 'Зарегистрироваться'; ?>
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <span style="font-size:13px;color:var(--ink-500);">Бесплатное участие · Сертификат <?php echo $pmSubscriptionOnly ? 'по подписке' : ('от ' . number_format((float)($webinar['certificate_price'] ?? 200), 0, ',', ' ') . ' ₽'); ?></span>
      </div>
    </div>

    <div class="rd-hero-art rd-hero-art-webinar reveal">
      <div class="rd-blob"></div>
      <div class="speaker-frame">
        <?php if (!empty($webinar['speaker_photo'])): ?>
          <img src="<?php echo htmlspecialchars($webinar['speaker_photo']); ?>"
               alt="<?php echo htmlspecialchars($webinar['speaker_name'] ?? ''); ?>"
               onerror="this.onerror=null;this.src='/assets/images/default-speaker.svg';">
        <?php else: ?>
          <img src="/assets/images/default-speaker.svg" alt="Спикер">
        <?php endif; ?>
      </div>
      <div class="skolkovo-pill">
        <img src="/assets/images/skolkovo.webp" alt=""> Резидент Сколково
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска / 4 шага -->
<section class="rd-path rd-section">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Что вас ждёт</div>
      <h2 class="rd-section-title">Четыре шага до сертификата</h2>
    </div>
    <div class="rd-steps four reveal-stagger">
      <?php if ($isAutowebinar): ?>
        <div class="rd-step"><div class="rd-step-n">1</div><h4>Бесплатный просмотр</h4><p>Смотрите запись в удобное для вас время.</p></div>
        <div class="rd-step"><div class="rd-step-n">2</div><h4>Тест из 5 вопросов</h4><p>Подтвердите знания — занимает несколько минут.</p></div>
        <div class="rd-step"><div class="rd-step-n">3</div><h4>Сертификат участника</h4><p>Именной сертификат на <?php echo (int)$webinar['certificate_hours']; ?> ак. ч.</p></div>
        <div class="rd-step"><div class="rd-step-n">4</div><h4>Мгновенный доступ</h4><p>Сразу после регистрации — запись и тест.</p></div>
      <?php else: ?>
        <div class="rd-step"><div class="rd-step-n">1</div><h4>Бесплатное участие</h4><p>Зарегистрируйтесь — место в эфире зарезервировано.</p></div>
        <div class="rd-step"><div class="rd-step-n">2</div><h4>Прямой онлайн-эфир</h4><p>Слушайте доклад и задавайте вопросы спикеру.</p></div>
        <div class="rd-step"><div class="rd-step-n">3</div><h4>Запись и материалы</h4><p>Чек-листы, презентации — для работы.</p></div>
        <div class="rd-step"><div class="rd-step-n">4</div><h4>Сертификат участника</h4><p>Именной сертификат на <?php echo (int)$webinar['certificate_hours']; ?> ак. ч.</p></div>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- О вебинаре + сайдбар -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="reveal rd-detail-head" style="margin-bottom:28px;">
      <div class="rd-eyebrow">О <?php echo $isAutowebinar ? 'видеолекции' : 'вебинаре'; ?></div>
      <h2 class="rd-section-title">Что внутри</h2>
    </div>
    <div class="rd-detail-grid">
      <div class="rd-detail-content">
        <?php echo $webinar['description']; ?>
      </div>
      <aside class="rd-detail-sidebar">
        <?php if (!empty($webinar['speaker_name'])): ?>
          <div class="sb-card">
            <h3>Спикер</h3>
            <p style="font-weight:600;margin:6px 0 4px;"><?php echo htmlspecialchars($webinar['speaker_name']); ?></p>
            <?php if (!empty($webinar['speaker_position'])): ?>
              <p style="color:var(--ink-500);font-size:13px;margin:0 0 4px;"><?php echo htmlspecialchars($webinar['speaker_position']); ?></p>
            <?php endif; ?>
            <?php if (!empty($webinar['speaker_organization'])): ?>
              <p style="color:var(--ink-500);font-size:13px;margin:0;"><?php echo htmlspecialchars($webinar['speaker_organization']); ?></p>
            <?php endif; ?>
            <?php if (!empty($webinar['speaker_video_url'])): ?>
              <video controls playsinline style="width:100%;margin-top:14px;border-radius:10px;">
                <source src="<?php echo htmlspecialchars($webinar['speaker_video_url']); ?>" type="video/mp4">
              </video>
            <?php endif; ?>
          </div>
        <?php endif; ?>
        <div class="sb-card">
          <h3>Сертификат</h3>
          <p style="margin:6px 0 0;color:var(--ink-600);font-size:14px;">Именной сертификат участника на <?php echo (int)$webinar['certificate_hours']; ?> ак. ч. для портфолио.</p>
          <div class="price"><?php echo $pmSubscriptionOnly ? 'По подписке' : (number_format((float)$webinar['certificate_price'], 0, ',', ' ') . ' ₽'); ?></div>
        </div>
      </aside>
    </div>
  </div>
</section>

<!-- Регистрация -->
<section class="rd-section" id="registration-form">
  <div class="rd-wrap">
    <div class="rd-reg-card reveal">
      <?php if ($isRegistered): ?>
        <div class="rd-already-registered" style="grid-column:1/-1;">
          <svg width="56" height="56" viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="#22c55e" stroke-width="2"/>
            <path d="M8 12l2.5 2.5L16 9" stroke="#22c55e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <p>Вы уже зарегистрированы!</p>
          <?php if ($isAutowebinar):
            $existingReg = $registrationObj->getByWebinarAndEmail($webinar['id'], $userEmail);
          ?>
            <a href="/kabinet/videolektsiya/<?php echo (int)$existingReg['id']; ?>" class="rd-btn rd-btn-primary">Перейти к видеолекции</a>
          <?php elseif (!empty($webinar['broadcast_url'])): ?>
            <a href="<?php echo htmlspecialchars($webinar['broadcast_url']); ?>" class="rd-btn rd-btn-primary" target="_blank">Перейти к трансляции</a>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div>
          <div class="rd-eyebrow">Регистрация</div>
          <h2 class="reg-title">Зарегистрируйтесь на <span class="hl"><?php echo $isAutowebinar ? 'видеолекцию' : 'вебинар'; ?></span></h2>
          <p style="color:var(--ink-600);margin:0;">Бесплатно. Ссылка придёт на email сразу после регистрации.</p>
          <ul class="reg-bullets">
            <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg><?php echo $isAutowebinar ? 'Доступ к записи сразу после регистрации' : 'Прямой эфир + возможность задать вопрос спикеру'; ?></li>
            <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Запись эфира и презентация в подарок</li>
            <li><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>Именной сертификат участника на <?php echo (int)$webinar['certificate_hours']; ?> ак. ч.</li>
          </ul>
        </div>
        <form id="webinarRegistrationForm" class="rd-reg-form">
          <input type="hidden" name="webinar_id" value="<?php echo (int)$webinar['id']; ?>">

          <div class="form-group">
            <input type="text" name="full_name" placeholder="Фамилия Имя Отчество *" required
                   value="<?php echo htmlspecialchars($_SESSION['user_name'] ?? ''); ?>">
          </div>
          <div class="form-group">
            <input type="email" name="email" placeholder="Email *" required
                   value="<?php echo htmlspecialchars($userEmail); ?>">
          </div>
          <div class="form-group">
            <div class="phone-input-wrapper">
              <span class="phone-flag">🇷🇺</span>
              <input type="tel" id="phone" name="phone" placeholder="+7 (___) ___-__-__">
            </div>
          </div>
          <div class="form-group">
            <label for="institution_type_id">Тип учреждения *</label>
            <select name="institution_type_id" id="institution_type_id" required>
              <option value="">Выберите тип учреждения</option>
              <?php
              require_once __DIR__ . '/../classes/AudienceType.php';
              $audienceTypeObj = new AudienceType($db);
              $institutionTypes = $audienceTypeObj->getAll(true);

              $userInstitutionTypeId = null;
              if (!empty($_SESSION['user_id'])) {
                  require_once __DIR__ . '/../classes/User.php';
                  $userObj = new User($db);
                  $currentUser = $userObj->getById($_SESSION['user_id']);
                  $userInstitutionTypeId = $currentUser['institution_type_id'] ?? null;
              }

              foreach ($institutionTypes as $type):
                  $selected = ($type['id'] == $userInstitutionTypeId) ? 'selected' : '';
              ?>
                <option value="<?php echo (int)$type['id']; ?>" <?php echo $selected; ?>>
                  <?php echo htmlspecialchars($type['name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-checkbox">
            <label>
              <input type="checkbox" name="agree" required>
              <span>Я принимаю условия <a href="/polzovatelskoe-soglashenie/" target="_blank">Пользовательского соглашения</a>, <a href="/oferta-meropriyatiya/" target="_blank">Договора-оферты</a> и даю согласие на <a href="/politika-konfidencialnosti/" target="_blank">обработку персональных данных</a></span>
            </label>
          </div>
          <div class="form-message" id="formMessage"></div>
          <button type="submit" class="btn-register" id="submitBtn">Зарегистрироваться</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</section>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-faq">
      <div class="reveal">
        <div class="rd-eyebrow">FAQ</div>
        <h2 class="rd-section-title">Частые вопросы</h2>
      </div>
      <?php renderFaqList($faqItems); ?>
    </div>
  </div>
</section>

<!-- Мобильная фиксированная кнопка -->
<div class="rd-mobile-cta" id="mobileFixedCta">
  <a href="#registration-form"><?php echo $isAutowebinar ? 'Получить доступ бесплатно' : 'Принять бесплатное участие'; ?></a>
</div>

<?php include __DIR__ . '/../includes/review-section.php'; ?>

<?php include __DIR__ . '/../includes/social-links.php'; ?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

<script src="/assets/js/webinars.js?v=<?php echo filemtime(__DIR__ . '/../assets/js/webinars.js'); ?>" defer></script>

<script>
// Фиксированная мобильная кнопка
if (window.innerWidth <= 768) {
    var heroCta = document.querySelector('.rd-hero-cta');
    var fixedCta = document.getElementById('mobileFixedCta');
    if (heroCta && fixedCta) {
        var obs = new IntersectionObserver(function(entries) {
            fixedCta.classList.toggle('visible', !entries[0].isIntersecting);
        }, { threshold: 0 });
        obs.observe(heroCta);
    }
}
</script>

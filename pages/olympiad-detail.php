<?php
/**
 * Olympiad Detail Page — редизайн (стиль competition-detail)
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../includes/session.php';

$slug = $_GET['slug'] ?? '';

if (empty($slug)) {
    header('Location: /olimpiady');
    exit;
}

$olympiadObj = new Olympiad($db);
$olympiad = $olympiadObj->getBySlug($slug);

if (!$olympiad) {
    http_response_code(404);
    $pageTitle = 'Олимпиада не найдена | ' . SITE_NAME;
    $pageDescription = 'Запрашиваемая олимпиада не найдена';
    $noindex = true;
    $rdActivePage = 'olimpiady';
    include __DIR__ . '/../includes/header-redesign.php';
    ?>
    <main>
      <section class="rd-section">
        <div class="rd-wrap" style="text-align:center;">
          <h1 style="font:700 36px var(--font-sans);color:var(--ink-900);margin-bottom:12px;">Олимпиада не найдена</h1>
          <p style="color:var(--ink-500);margin-bottom:24px;">Возможно, она была удалена или перемещена.</p>
          <a href="/olimpiady" class="rd-btn rd-btn-primary">Все олимпиады</a>
        </div>
      </section>
    </main>
    <?php
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

$audienceLabel = Olympiad::getAudienceLabel($olympiad['target_audience']);
$diplomaPrice  = (int)($olympiad['diploma_price'] ?? 229);
$academicYear  = $olympiad['academic_year'] ?? '2025-2026';

$pageTitle       = htmlspecialchars($olympiad['title']) . ' | Олимпиады | ' . SITE_NAME;
$pageDescription = htmlspecialchars(mb_substr($olympiad['description'], 0, 150));
$canonicalUrl    = SITE_URL . '/olimpiady/' . $olympiad['slug'] . '/';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Quiz',
    'name' => $olympiad['title'],
    'description' => mb_substr(strip_tags($olympiad['description']), 0, 300),
    'url' => SITE_URL . '/olimpiady/' . $olympiad['slug'] . '/',
    'educationalLevel' => $olympiad['grade'] ?? '',
    'provider' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL]
];
$ogType  = 'article';
$ogImage = SITE_URL . '/og-image/olympiad/' . $olympiad['slug'] . '.jpg';

$rdActivePage  = 'olimpiady';
$additionalCSS = [
    '/assets/css/competition-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/competition-detail.css'),
    '/assets/css/olympiad-detail.css?v=' . filemtime(__DIR__ . '/../assets/css/olympiad-detail.css'),
];

$testUrl = '/olimpiada-test/' . (int)$olympiad['id'];
$groupRegistrationUrl = '/pages/group-registration.php?product_type=olympiad&product_id=' . (int)$olympiad['id'];

// FAQ-блок + микроразметка Schema.org/FAQPage
require_once __DIR__ . '/../includes/faq-helper.php';
$faqItems = [
    ['q' => 'Как проходит олимпиада?', 'a' => 'Олимпиада проходит в онлайн-формате. После регистрации вам будет предложено ответить на 10 вопросов по теме. Время прохождения не ограничено. Вы увидите свой результат сразу после завершения теста.'],
    ['q' => 'Участие действительно бесплатное?', 'a' => 'Да, участие в олимпиаде полностью бесплатное. Оплата требуется только в том случае, если вы захотите получить именной диплом. Стоимость оформления диплома составляет ' . $diplomaPrice . ' руб.'],
    ['q' => 'Как работает акция «2+1»?', 'a' => 'При оформлении трёх дипломов вы оплачиваете только два — третий (самый дешёвый в заказе) мы добавляем бесплатно. Акция применяется в корзине автоматически и действует на все дипломы и сертификаты вместе: олимпиады, конкурсы и вебинары можно комбинировать. Пройдите ещё две олимпиады по своим темам, и третий диплом будет бесплатным.'],
    ['q' => 'Какие вопросы в олимпиаде?', 'a' => 'Олимпиада содержит 10 вопросов в формате теста с вариантами ответов. Вопросы составлены профессиональными методистами и соответствуют тематике олимпиады.'],
    ['q' => 'Как определяется место участника?', 'a' => 'Место определяется по количеству правильных ответов: 9–10 правильных — 1 место, 8 — 2 место, 7 — 3 место. При результате менее 7 — статус участника.'],
    ['q' => 'Можно ли пройти олимпиаду повторно?', 'a' => 'Да, вы можете пройти олимпиаду повторно для улучшения результата. Каждая попытка генерирует новый набор вопросов. При оформлении диплома используется лучший из результатов.'],
    ['q' => 'Какие документы подтверждают легитимность?', 'a' => 'Портал работает на основании лицензии на образовательную деятельность № Л035-01212-59/00203856 и свидетельства о регистрации СМИ Эл. №ФС 77-74524. Также мы — резидент «Сколково». Все дипломы являются официальными документами.'],
];
// Отзывы продукта + микроразметка рейтинга (aggregateRating/review)
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'olympiad';
$reviewEntityId   = (int)$olympiad['id'];
$reviewObj   = new Review($db);
$reviewStats = $reviewObj->getStats($reviewEntityType, $reviewEntityId);
$reviewList  = $reviewObj->getApproved($reviewEntityType, $reviewEntityId, 20);
$jsonLd = applyReviewSchema($jsonLd, $reviewStats, $reviewList);
$additionalCSS[] = '/assets/css/reviews.css?v=' . filemtime(__DIR__ . '/../assets/css/reviews.css');
$additionalJS = $additionalJS ?? [];
$additionalJS[] = '/assets/js/reviews.js?v=' . filemtime(__DIR__ . '/../assets/js/reviews.js');

$jsonLdArray = [$jsonLd, buildFaqJsonLd($faqItems)];

include __DIR__ . '/../includes/header-redesign.php';
?>

<main>

<!-- HERO -->
<section class="cd-hero">
  <div class="rd-wrap">
    <div class="cd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/olimpiady">Олимпиады</a>
      <span class="sep">/</span>
      <strong><?php echo htmlspecialchars($olympiad['title']); ?></strong>
    </div>

    <div class="cd-hero-grid">
      <div class="cd-hero-content">
        <div class="rd-pill-row reveal-stagger">
          <span class="rd-pill free"><span class="dot"></span>Бесплатное участие</span>
          <?php if (!empty($audienceLabel)): ?>
            <span class="rd-pill indigo"><?php echo htmlspecialchars($audienceLabel); ?></span>
          <?php endif; ?>
          <?php if (!empty($olympiad['subject'])): ?>
            <span class="rd-pill"><?php echo htmlspecialchars($olympiad['subject']); ?></span>
          <?php endif; ?>
          <span class="rd-pill" style="background:#FFF3E0;color:#C2410C;border-color:#FFD9A8;">🎁 2+1: третий диплом бесплатно</span>
        </div>

        <h1 class="cd-hero-title reveal"><?php echo htmlspecialchars($olympiad['title']); ?></h1>

        <p class="rd-hero-sub reveal" style="margin-top:18px;color:var(--ink-700);font-size:17px;line-height:1.55;">
          <?php echo htmlspecialchars(mb_substr($olympiad['description'], 0, 220)); ?><?php echo mb_strlen($olympiad['description']) > 220 ? '…' : ''; ?>
        </p>

        <div class="cd-hero-bullets reveal-stagger">
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Дистанционный формат</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>10 вопросов в тесте</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Результат сразу</div>
          <div class="b"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Соответствует ФГОС</div>
        </div>

        <div class="cd-hero-cta reveal">
          <a href="<?php echo $testUrl; ?>" class="rd-btn rd-btn-primary">
            Пройти олимпиаду бесплатно
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

      <!-- ВЕЕР ДИПЛОМОВ -->
      <div class="cd-hero-art reveal">
        <div class="hero-diploma">
          <div class="diploma-stack">
            <div class="diploma-item diploma-1"><img src="/assets/images/diplomas/previews/diploma-1.svg" alt="Диплом вариант 1"></div>
            <div class="diploma-item diploma-2"><img src="/assets/images/diplomas/previews/diploma-2.svg" alt="Диплом вариант 2"></div>
            <div class="diploma-item diploma-3"><img src="/assets/images/diplomas/previews/diploma-3.svg" alt="Диплом вариант 3"></div>
            <div class="diploma-item diploma-4"><img src="/assets/images/diplomas/previews/diploma-4.svg" alt="Диплом вариант 4"></div>
            <div class="diploma-item diploma-5"><img src="/assets/images/diplomas/previews/diploma-5.svg" alt="Диплом вариант 5"></div>
            <div class="diploma-item diploma-6"><img src="/assets/images/diplomas/previews/diploma-6.svg" alt="Диплом вариант 6"></div>
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
        <p>Узнайте результат сразу после прохождения теста.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="M9 15l2 2 4-4"/></svg></div>
        <h3>Официальный документ</h3>
        <p>Диплом от издания с регистрацией СМИ для портфолио.</p>
      </div>
      <div class="cd-benefit">
        <div class="ic"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20M2 12h20"/></svg></div>
        <h3>Бесплатное участие</h3>
        <p>Тест бесплатный — оплата только за оформление диплома.</p>
      </div>
    </div>
  </div>
</section>

<!-- ЛИЦЕНЗИИ И АККРЕДИТАЦИИ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Документы</div>
        <h2 class="rd-section-title">Лицензия и аккредитации</h2>
      </div>
      <p class="rd-section-sub">Наш портал имеет все необходимые документы для ведения образовательной деятельности.</p>
    </div>
    <div class="od-license-grid reveal-stagger">
      <div class="od-license-card">
        <img src="/assets/images/cropped-logo_rosobrnadzor-2.png" alt="Рособрнадзор" class="od-license-card-logo">
        <h3>Образовательная лицензия</h3>
        <p>Лицензия на образовательную деятельность № Л035-01212-59/00203856 от 17.12.2021 г.</p>
        <a href="https://islod.obrnadzor.gov.ru/rlic/details/c197b78b-ee10-1b2e-3837-6f0b1295bc1f/" target="_blank" rel="noopener noreferrer nofollow" class="od-license-card-link">
          Проверить лицензию
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>
      </div>
      <div class="od-license-card">
        <img src="/assets/images/eagle_s.svg" alt="Роскомнадзор" class="od-license-card-logo">
        <h3>Официальное СМИ</h3>
        <p>Свидетельство о регистрации СМИ Эл. №ФС 77-74524 от 24.12.2018.</p>
        <a href="https://rkn.gov.ru/activity/mass-media/for-founders/media/?id=700411&page=" target="_blank" rel="noopener noreferrer nofollow" class="od-license-card-link">
          Проверить свидетельство
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
        </a>
      </div>
      <div class="od-license-card">
        <img src="/assets/images/skolkovo-logo.svg" alt="Сколково" class="od-license-card-logo">
        <h3>Резидент Сколково</h3>
        <p>Резидент инновационного центра «Сколково» №1127165 от 18.02.2025.</p>
        <a href="/assets/files/Выписка_из_реестра_Сколково_12_01_2026.pdf" download class="od-license-card-link">
          Скачать выписку
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        </a>
      </div>
    </div>
  </div>
</section>

<!-- КАК ПРОХОДИТ ОЛИМПИАДА -->
<section class="rd-section rd-path">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Как пройти</div>
        <h2 class="rd-section-title">4 шага до диплома</h2>
      </div>
      <p class="rd-section-sub">Простой процесс — займёт не больше 10 минут.</p>
    </div>
    <div class="rd-steps four reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Регистрация</h4>
        <p>Участие бесплатное. Укажите email и ФИО.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Прохождение теста</h4>
        <p>10 вопросов по теме олимпиады в формате тестирования.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Результат</h4>
        <p>Узнайте свой результат и место среди участников.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">4</div>
        <h4>Диплом</h4>
        <p>Оформите именной диплом за <?php echo $diplomaPrice; ?>&nbsp;₽. По акции «2+1» каждый третий диплом&nbsp;— бесплатно.</p>
      </div>
    </div>
  </div>
</section>

<!-- ОБ ОЛИМПИАДЕ -->
<?php if (!empty($olympiad['seo_content']) || !empty($olympiad['description'])): ?>
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <span class="rd-eyebrow">Об олимпиаде</span>
      <h2 class="rd-section-title">Что нужно знать</h2>
    </div>

    <div class="cd-about-grid">
      <div class="reveal">
        <div class="rd-prose">
          <?php if (!empty($olympiad['seo_content'])): ?>
            <?php echo $olympiad['seo_content']; ?>
          <?php else: ?>
            <?php
            $paragraphs = explode("\n\n", $olympiad['description']);
            foreach ($paragraphs as $paragraph):
                if (empty(trim($paragraph))) continue;
            ?>
              <p><?php echo nl2br(htmlspecialchars($paragraph)); ?></p>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>

      <div class="cd-info-cards reveal-stagger">
        <div class="cd-info-card cd-i-noms">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="14" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <h3>Формат</h3>
          </div>
          <div class="body">Дистанционный, онлайн-тестирование из 10 вопросов</div>
        </div>

        <div class="cd-info-card cd-i-awards">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="6"/><path d="M15.477 12.89 17 22l-5-3-5 3 1.523-9.11"/></svg></div>
            <h3>Награды</h3>
          </div>
          <div class="body">Диплом I, II, III степени в электронном виде</div>
        </div>

        <div class="cd-info-card cd-i-year">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg></div>
            <h3>Учебный год</h3>
          </div>
          <div class="body"><?php echo htmlspecialchars($academicYear); ?></div>
        </div>

        <div class="cd-info-card cd-i-price">
          <div style="display:flex;gap:12px;align-items:center;">
            <div class="ic"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21V3h5a4 4 0 0 1 0 8H6"/><path d="M6 15h8"/></svg></div>
            <h3>Стоимость диплома</h3>
          </div>
          <div class="price-row"><?php echo $diplomaPrice; ?> ₽</div>
          <div class="price-note">Тест — бесплатно, оплата только за оформление</div>
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
      <div class="label">Участие в олимпиаде</div>
      <div class="amount">Бесплатно</div>
      <div class="note">Диплом за <?php echo $diplomaPrice; ?>&nbsp;₽ — оформляется по желанию после прохождения теста. По акции «2+1» при оплате двух дипломов третий&nbsp;— бесплатно. Для группы&nbsp;— скидка до&nbsp;30%.</div>
      <a href="<?php echo $testUrl; ?>" class="rd-btn rd-btn-primary">
        Пройти олимпиаду
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
      </a>
      <a href="<?php echo $groupRegistrationUrl; ?>" class="rd-btn rd-btn-ghost cd-group-cta">
        Оформить на группу / весь класс
      </a>
    </div>
  </div>
</section>

<!-- ТЁМНЫЙ CTA-БАННЕР -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal" style="text-align:center;">
      <div class="rd-trust-head" style="max-width:none;">
        <span class="rd-eyebrow">Готовы попробовать?</span>
        <h2 style="color:#fff;font:700 clamp(28px, 3vw, 40px) var(--font-sans);letter-spacing:-.02em;margin:0 0 12px;">Пройдите олимпиаду прямо сейчас</h2>
        <p style="color:#c2cdff;font-size:16px;line-height:1.55;margin:0 0 28px;">10 вопросов · бесплатно · результат сразу</p>
        <a href="<?php echo $testUrl; ?>" class="rd-btn rd-btn-primary" style="background:#fff;color:var(--indigo-700);">
          Пройти бесплатно
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <div style="margin-top:24px;display:flex;gap:24px;justify-content:center;flex-wrap:wrap;color:#d6deff;font-size:14px;">
          <span>✓ Бесплатно</span>
          <span>✓ Дистанционно</span>
          <span>✓ Диплом сразу</span>
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
  <a href="<?php echo $testUrl; ?>" class="rd-btn rd-btn-primary">
    Пройти олимпиаду бесплатно
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
  </a>
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

<script>
window.dataLayer = window.dataLayer || [];
window.dataLayer.push({
    "ecommerce": {
        "currencyCode": "RUB",
        "detail": {
            "actionField": {"list": "<?php echo htmlspecialchars($audienceLabel, ENT_QUOTES); ?>"},
            "products": [{
                "id": "<?php echo (int)$olympiad['id']; ?>",
                "name": "<?php echo htmlspecialchars($olympiad['title'], ENT_QUOTES); ?>",
                "price": <?php echo $diplomaPrice; ?>,
                "brand": "Педпортал",
                "category": "Олимпиады"
            }]
        }
    }
});
</script>

<?php include __DIR__ . '/../includes/review-section.php'; ?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

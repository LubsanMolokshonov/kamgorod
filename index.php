<?php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';
require_once __DIR__ . '/classes/Competition.php';
require_once __DIR__ . '/classes/Webinar.php';
require_once __DIR__ . '/classes/Publication.php';
require_once __DIR__ . '/classes/AudienceType.php';
require_once __DIR__ . '/classes/Olympiad.php';
require_once __DIR__ . '/classes/Course.php';
require_once __DIR__ . '/includes/session.php';

$pageTitle = 'ФГОС-Практикум — конкурсы, курсы и вебинары для педагогов';
$pageDescription = 'Всероссийский педагогический портал. Конкурсы и олимпиады с официальными дипломами, курсы повышения квалификации и публикации в зарегистрированном СМИ. Резидент Сколково.';
$canonicalUrl = SITE_URL . '/';
$ogImage = SITE_URL . '/assets/images/og-home.jpg';

$jsonLd = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'WebSite',
            'name'  => SITE_NAME . ' — конкурсы и курсы для педагогов',
            'url'   => SITE_URL . '/',
            'description' => $pageDescription,
        ],
        [
            '@type' => 'EducationalOrganization',
            'name'  => SITE_NAME,
            'url'   => SITE_URL . '/',
            'logo'  => SITE_URL . '/assets/images/logo.svg',
            'description' => 'Официальное СМИ и резидент Сколково. '
                . 'Лицензия на образовательную деятельность.',
            'founder' => [
                '@type' => 'Person',
                'name'  => 'Александр Дмитриевич Воронов',
            ],
            'foundingDate' => '2018-12-24',
            'address' => [
                '@type' => 'PostalAddress',
                'addressCountry' => 'RU',
                'addressLocality' => 'Пермь',
            ],
            'sameAs' => [
                'https://vk.com/fgospro',
                'https://t.me/merovospitatel',
                'https://t.me/eduregion_do',
            ],
        ],
    ],
];

// Данные из БД
$competitionObj = new Competition($db);
$webinarObj     = new Webinar($db);
$publicationObj = new Publication($db);
$olympiadObj    = new Olympiad($db);
$courseObj      = new Course($db);

$totalCompetitions = count($competitionObj->getActiveCompetitions('all'));
$topCompetitions   = $competitionObj->getTopCompetitions(6);
$webinarCounts     = $webinarObj->countByStatus();
$topWebinars       = $webinarObj->getTopWebinars(6);
$totalWebinars     = ($webinarCounts['upcoming'] ?? 0) + ($webinarCounts['recordings'] ?? 0) + ($webinarCounts['autowebinars'] ?? 0);

try {
    $publicationCount = $publicationObj->getPublishedCount();
    $topPublications  = $publicationObj->getPopular(6);
} catch (Exception $e) {
    $publicationCount = 0;
    $topPublications  = [];
}

$totalOlympiads = $olympiadObj->count();
$topOlympiads   = $olympiadObj->getTopOlympiads(6);
$totalCourses   = $courseObj->count();
$topCourses     = array_slice($courseObj->getActiveCourses(), 0, 6);

// Данные для JS-табов
$offersData = [
    'kursy' => array_map(function ($c) {
        return [
            'tag'   => ($c['program_type'] === 'pp' ? 'Переподготовка' : 'Повышение квалификации') . ' · ' . $c['hours'] . ' ч',
            'title' => $c['title'],
            'meta'  => $c['hours'] . ' ч · удостоверение/сертификат',
            'price' => number_format($c['price'], 0, ',', ' ') . ' ₽',
            'url'   => '/kursy/' . $c['slug'],
        ];
    }, $topCourses),
    'konk' => array_map(function ($c) {
        return [
            'tag'   => 'Конкурс · ' . htmlspecialchars($c['category_name'] ?? '', ENT_QUOTES, 'UTF-8'),
            'title' => $c['title'],
            'meta'  => 'от ' . number_format($c['price'], 0, ',', ' ') . ' ₽',
            'price' => number_format($c['price'], 0, ',', ' ') . ' ₽',
            'url'   => '/konkursy/' . $c['slug'],
        ];
    }, $topCompetitions),
    'veb' => array_map(function ($w) {
        $typeLabels = ['upcoming' => 'Вебинар', 'recording' => 'Запись', 'videolecture' => 'Видеолекция'];
        return [
            'tag'   => $typeLabels[$w['status']] ?? 'Вебинар',
            'title' => $w['title'],
            'meta'  => !empty($w['speaker_name']) ? 'Спикер: ' . $w['speaker_name'] : '',
            'price' => !empty($w['is_free']) ? 'Бесплатно' : (isset($w['price']) ? number_format($w['price'], 0, ',', ' ') . ' ₽' : ''),
            'free'  => !empty($w['is_free']),
            'url'   => '/vebinar/' . $w['slug'],
        ];
    }, $topWebinars),
    'ol' => array_map(function ($o) {
        return [
            'tag'   => 'Олимпиада',
            'title' => $o['title'],
            'meta'  => '10 вопросов · диплом сразу',
            'price' => 'Бесплатно',
            'free'  => true,
            'url'   => '/olimpiady/' . $o['slug'],
        ];
    }, $topOlympiads),
    'pub' => array_map(function ($p) {
        return [
            'tag'   => $p['type_name'] ?? 'Публикация',
            'title' => $p['title'],
            'meta'  => $p['author_name'] . ' · ' . date('d.m.Y', strtotime($p['published_at'])),
            'price' => 'Читать',
            'url'   => '/publikaciya/' . $p['slug'] . '/',
        ];
    }, $topPublications),
];

include __DIR__ . '/includes/header-redesign.php';
?>

<!-- HERO -->
<section class="rd-hero">
  <div class="rd-grid-bg"></div>
  <div class="rd-wrap rd-hero-grid">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span>Резидент Сколково</span>
        <span class="rd-pill indigo">Лицензия № Л035-01212-59</span>
        <span class="rd-pill">СМИ Эл. №ФС 77-74524</span>
      </div>
      <h1 class="rd-hero-title reveal">Найдите конкурс, курс или вебинар <span class="accent">за пару кликов</span></h1>
      <p class="rd-hero-sub reveal">Всероссийский педагогический портал. Конкурсы и олимпиады с официальными дипломами, курсы повышения квалификации и публикации в зарегистрированном СМИ.</p>
      <div class="rd-hero-cta reveal">
        <a href="/konkursy" class="rd-btn rd-btn-primary">Подобрать конкурс
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/kursy/perepodgotovka/" class="rd-btn rd-btn-ghost">Все курсы профессиональной переподготовки</a>
      </div>
      <div class="rd-hero-trust reveal-stagger">
        <div class="rd-trust-item">
          <div class="rd-trust-num"><?php echo $totalCompetitions; ?>+</div>
          <div class="rd-trust-label">активных конкурсов</div>
        </div>
        <div class="rd-trust-item">
          <div class="rd-trust-num"><?php echo $totalOlympiads; ?>+</div>
          <div class="rd-trust-label">олимпиад с дипломом</div>
        </div>
        <div class="rd-trust-item">
          <div class="rd-trust-num"><?php echo $totalCourses; ?>+</div>
          <div class="rd-trust-label">программ обучения</div>
        </div>
        <div class="rd-trust-item">
          <div class="rd-trust-num"><?php echo $publicationCount; ?>+</div>
          <div class="rd-trust-label">опубликованных работ</div>
        </div>
      </div>
    </div>

    <div class="rd-hero-art reveal">
      <div class="rd-blob"></div>
      <div class="rd-hero-circle">
        <div class="rd-hero-figure">
          <div class="rd-diploma">
            <span class="ribbon"></span>
            <div style="font:700 13px var(--font-sans);color:var(--indigo-700);letter-spacing:.04em;">ДИПЛОМ</div>
            <div style="font:600 12px var(--font-sans);color:var(--ink-500);">Всероссийский конкурс</div>
            <div class="lines"><span></span><span></span><span></span></div>
            <div class="stamp">ФГОС<br>.ПРО</div>
          </div>
        </div>
      </div>
      <div class="rd-float-card rd-fc-1">
        <div class="rd-fc-icon">🏆</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Диплом за 30 сек.</div><div class="rd-fc-s">после прохождения олимпиады</div></div>
      </div>
      <div class="rd-float-card rd-fc-2">
        <div class="rd-fc-icon">✓</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Принимается при аттестации</div><div class="rd-fc-s">официальное СМИ</div></div>
      </div>
      <div class="rd-float-card rd-fc-3">
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Оплата онлайн</div><div class="rd-fc-s">ЮКасса · от 169 ₽</div></div>
      </div>
    </div>
  </div>
</section>

<!-- 5 направлений -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Что вы найдёте на портале</div>
        <h2 class="rd-section-title">Пять направлений для развития педагога</h2>
      </div>
      <p class="rd-section-sub">Выберите формат — и переходите к участию. Все мероприятия с подтверждением: дипломы, сертификаты, удостоверения.</p>
    </div>
    <div class="rd-feat-grid reveal-stagger">
      <a class="rd-feat rd-feat-1 span-6" href="/konkursy">
        <div class="rd-feat-pat"></div>
        <div class="ic">🏆</div>
        <h3>Всероссийские конкурсы</h3>
        <p>Для педагогов всех уровней образования. Официальные дипломы для портфолио и аттестации. Участие — от 169 ₽.</p>
        <div class="rd-feat-foot">
          <div class="rd-feat-num"><?php echo $totalCompetitions; ?>+ <small>активных конкурсов</small></div>
          <div class="rd-feat-go">→</div>
        </div>
      </a>
      <a class="rd-feat rd-feat-2 span-6" href="/olimpiady">
        <div class="rd-feat-pat"></div>
        <div class="ic">🎓</div>
        <h3>Всероссийские олимпиады</h3>
        <p>Бесплатное участие для педагогов и учеников. Тест из 10 вопросов — диплом приходит за 30 секунд.</p>
        <div class="rd-feat-foot">
          <div class="rd-feat-num"><?php echo $totalOlympiads; ?>+ <small>олимпиад</small></div>
          <div class="rd-feat-go">→</div>
        </div>
      </a>
      <a class="rd-feat rd-feat-3" href="/vebinary">
        <div class="rd-feat-pat"></div>
        <div class="ic">🎤</div>
        <h3>Вебинары</h3>
        <p>Живые трансляции и записи от ведущих экспертов. Сертификаты участника.</p>
        <div class="rd-feat-foot">
          <div class="rd-feat-num"><?php echo $totalWebinars; ?>+ <small>вебинаров</small></div>
          <div class="rd-feat-go">→</div>
        </div>
      </a>
      <a class="rd-feat rd-feat-4" href="/zhurnal">
        <div class="rd-feat-pat"></div>
        <div class="ic">📝</div>
        <h3>Журнал</h3>
        <p>Публикуйте методические разработки и делитесь опытом. Свидетельство о публикации.</p>
        <div class="rd-feat-foot">
          <div class="rd-feat-num"><?php echo $publicationCount; ?>+ <small>работ</small></div>
          <div class="rd-feat-go">→</div>
        </div>
      </a>
      <a class="rd-feat rd-feat-5" href="/kursy">
        <div class="rd-feat-pat"></div>
        <div class="ic">📚</div>
        <h3>Курсы повышения квалификации и переподготовки</h3>
        <p>Программы КПК и профессиональной переподготовки с удостоверением.</p>
        <div class="rd-feat-foot">
          <div class="rd-feat-num"><?php echo $totalCourses; ?>+ <small>программ</small></div>
          <div class="rd-feat-go">→</div>
        </div>
      </a>
    </div>
  </div>
</section>

<!-- Преимущества (GEO-блок) -->
<section class="rd-section tight rd-advantages-section">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Почему мы</div>
      <h2 class="rd-section-title">Почему педагоги выбирают ФГОС.ПРО</h2>
    </div>
    <ul class="rd-advantages reveal">
      <li>✅ Диплом за 2 рабочих дня — или до 5 дней при загрузке</li>
      <li>✅ Принимается при аттестации во всех регионах РФ</li>
      <li>✅ Резидент Сколково и официальное СМИ (проверьте в реестре)</li>
      <li>✅ Цена от 169 ₽, акция «2+1» — третье участие бесплатно</li>
      <li>✅ Бессрочное хранение дипломов в личном кабинете</li>
    </ul>
  </div>
</section>

<!-- 3 шага до диплома -->
<section class="rd-path rd-section tight">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Как это работает</div>
      <h2 class="rd-section-title">Три шага до диплома</h2>
    </div>
    <div class="rd-steps reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите мероприятие</h4>
        <p>Конкурс, олимпиаду, вебинар или курс. Фильтр по уровню образования и теме.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Оформите участие</h4>
        <p>Регистрация за минуту. Оплата картой через ЮКассу — защищено по PCI DSS.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Получите документ</h4>
        <p>Диплом, сертификат или удостоверение в личный кабинет — храним бессрочно.</p>
      </div>
    </div>
  </div>
</section>

<!-- Актуальные предложения (табы) -->
<section class="rd-section" style="background:var(--ink-50);">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Актуальные предложения</div>
        <h2 class="rd-section-title">Самые популярные мероприятия портала</h2>
      </div>
    </div>
    <div class="rd-tabs-bar reveal" id="rdTabsBar">
      <button class="rd-tab active" data-tab="kursy">ТОП курсы</button>
      <button class="rd-tab" data-tab="konk">ТОП конкурсы</button>
      <button class="rd-tab" data-tab="veb">ТОП вебинары</button>
      <button class="rd-tab" data-tab="ol">ТОП олимпиады</button>
      <button class="rd-tab" data-tab="pub">ТОП публикации</button>
    </div>
    <div class="rd-offers-grid" id="rdOffersGrid"></div>
  </div>
</section>
<script>
window.rdOffersData = <?php echo json_encode($offersData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
</script>

<!-- Trust band -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal">
      <div class="rd-trust-head rd-section-head">
        <div>
          <div class="rd-eyebrow">Документы и аккредитации</div>
          <h2 class="rd-section-title">Все документы в порядке. Можно проверить.</h2>
        </div>
        <p class="rd-section-sub">Мы — официальное СМИ и резидент Сколково с лицензией на образовательную деятельность. Каждый документ можно проверить по реестру.</p>
      </div>
      <div class="rd-trust-cards reveal-stagger">
        <div class="rd-trust-card">
          <div class="badge">📜</div>
          <h4>Образовательная лицензия</h4>
          <p>№ Л035-01212-59/00203856 от 17.12.2021</p>
          <a href="https://islod.obrnadzor.gov.ru/rlic/details/c197b78b-ee10-1b2e-3837-6f0b1295bc1f/" target="_blank" rel="noopener noreferrer nofollow">Проверить в реестре <span>→</span></a>
        </div>
        <div class="rd-trust-card">
          <div class="badge">📰</div>
          <h4>Официальное СМИ</h4>
          <p>Свидетельство Эл. №ФС 77-74524 от 24.12.2018</p>
          <a href="https://rkn.gov.ru/activity/mass-media/for-founders/media/?id=700411&page=" target="_blank" rel="noopener noreferrer nofollow">Проверить в Роскомнадзоре <span>→</span></a>
        </div>
        <div class="rd-trust-card">
          <div class="badge">⚡</div>
          <h4>Резидент Сколково</h4>
          <p>№1127165 от 18.02.2025 — инновационный центр</p>
          <a href="/assets/files/Выписка_из_реестра_Сколково_12_01_2026.pdf" download>Скачать выписку <span>→</span></a>
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
        <h2 class="rd-section-title">Частые вопросы</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите на <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>. Ежедневно 9:00–21:00.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Вы выдаёте официальные дипломы? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, все дипломы выдаются от имени зарегистрированного СМИ (Эл. №ФС 77-74524) и являются официальными документами. Принимаются при аттестации педагогов, для портфолио учителей и учеников.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Как можно оплатить участие? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Оплата через ЮКассу — банковской картой (Visa, MasterCard, МИР), электронными кошельками или со счёта мобильного. Все платежи защищены по стандарту PCI DSS.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Сколько стоит участие в конкурсе? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Стоимость зависит от конкурса и номинации. Базовая — от 169 ₽. По акции «2+1»: при оплате двух участий третье — бесплатно.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Есть ли образовательная лицензия? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да, № Л035-01212-59/00203856 от 17.12.2021. Портал также является резидентом инновационного центра «Сколково».</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Сколько хранятся дипломы в личном кабинете? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Бессрочно. Скачать диплом снова можно в любой момент.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Сколько времени займёт получение диплома? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Обработка заявки и выдача диплома — до 2 рабочих дней после оплаты. При большой загрузке срок может увеличиться до 3–5 дней.</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Соцсети -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Сообщество</div>
        <h2 class="rd-section-title">Мы в социальных сетях</h2>
      </div>
      <p class="rd-section-sub">Подписывайтесь, чтобы быть в курсе новостей и обновлений</p>
    </div>
    <div class="rd-socials reveal-stagger">
      <a class="rd-social" href="https://vk.com/fgospro" target="_blank" rel="noopener noreferrer nofollow">
        <div class="si si-vk">VK</div>
        <div><div class="st">ВКонтакте</div><div class="ss">700+ подписчиков</div></div>
      </a>
      <a class="rd-social" href="https://t.me/merovospitatel" target="_blank" rel="noopener noreferrer nofollow">
        <div class="si si-tg">TG</div>
        <div><div class="st">Telegram-канал</div><div class="ss">200+ подписчиков</div></div>
      </a>
      <a class="rd-social" href="https://t.me/eduregion_do" target="_blank" rel="noopener noreferrer nofollow">
        <div class="si si-tg2">⌬</div>
        <div><div class="st">Telegram-чат</div><div class="ss">900+ участников</div></div>
      </a>
      <a class="rd-social" href="https://max.ru/id5904368615_biz" target="_blank" rel="noopener noreferrer nofollow">
        <div class="si si-mx">МАX</div>
        <div><div class="st">Макс</div><div class="ss">100+ подписчиков</div></div>
      </a>
      <a class="rd-social" href="https://yandex.ru/maps/-/CPcmAX~R" target="_blank" rel="noopener noreferrer">
        <div class="si si-ya">Я</div>
        <div><div class="st">Яндекс.Карты</div><div class="ss">Карточка организации</div></div>
      </a>
      <a class="rd-social" href="https://2gis.ru/moscow/firm/70000001112964399" target="_blank" rel="noopener noreferrer">
        <div class="si si-2gis">2ГИС</div>
        <div><div class="st">2ГИС</div><div class="ss">Карточка организации</div></div>
      </a>
    </div>
  </div>
</section>

<?php include __DIR__ . '/includes/footer-redesign.php'; ?>

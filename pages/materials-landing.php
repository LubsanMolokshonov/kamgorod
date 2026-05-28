<?php
/**
 * Лендинг раздела «Материалы ФОП» — /materialy/
 *
 * Продаёт педагогу идею: создавай материалы через ИИ и адаптируй готовые
 * под свой класс / ОВЗ / ФОП-2026. Ведёт на /material-generator/,
 * /material-adapter/, /materialy/katalog/ и тарифы токенов.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/TokenPackage.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/session.php';

$materialObj = new Material($db);
$typeObj     = new MaterialType($db);
$packageObj  = new TokenPackage($db);

$types        = $typeObj->getWithCounts();
$packages     = $packageObj->getActive();
$latest       = $materialObj->getPublished(8, 0, ['sort' => 'date']);
$totalCount   = $materialObj->countPublished([]);

$userId  = $_SESSION['user_id'] ?? null;
$balance = null;
if ($userId) {
    $tokens  = new UserTokens($db);
    $balance = $tokens->getBalance((int)$userId);
}

// Иконки типов: в БД хранятся FontAwesome-классы, но FA на сайте не подключён —
// показываем эмодзи по слагу.
$typeEmoji = [
    'tehkarta-uroka'  => '🗺️',
    'konspekt-uroka'  => '📝',
    'rabochiy-list'   => '📋',
    'test-kontrolnaya' => '✅',
    'prezentatsiya'   => '📊',
    'klassnyy-chas'   => '👥',
    'ktp-fragment'    => '📅',
];

$pageTitle = 'Материалы ФОП через ИИ — создавайте и адаптируйте уроки за 30 секунд | ' . SITE_NAME;
$pageDescription = 'Генерируйте техкарты, конспекты, рабочие листы, тесты, презентации и классные часы под ФОП и ФАОП ОВЗ. Адаптируйте свои материалы под класс через ИИ. 100 токенов в подарок.';
$canonicalUrl = SITE_URL . '/materialy/';
$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

$schema = [
    '@context' => 'https://schema.org',
    '@type'    => 'Service',
    'name'     => 'ИИ-генератор и адаптер материалов ФОП',
    'description' => $pageDescription,
    'url'      => $canonicalUrl,
    'provider' => ['@type' => 'Organization', 'name' => SITE_NAME, 'url' => SITE_URL],
    'areaServed' => 'RU',
    'audience'   => ['@type' => 'EducationalAudience', 'educationalRole' => 'teacher'],
];
$schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

include __DIR__ . '/../includes/header-redesign.php';
?>

<script type="application/ld+json"><?= $schemaJson ?></script>

<!-- HERO -->
<section class="rd-hero-catalog mat-hero">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <strong>Материалы ФОП</strong>
    </div>
  </div>
  <div class="rd-wrap rd-hero-grid" style="margin-top:24px;">
    <div>
      <div class="rd-pill-row reveal-stagger">
        <span class="rd-pill"><span class="dot"></span>Под ФГОС 2026 · ФОП · ФАОП ОВЗ</span>
        <span class="rd-pill indigo">100 токенов в подарок</span>
        <span class="rd-pill">DOCX · PDF · PPTX</span>
      </div>
      <h1 class="rd-hero-title rd-hero-title-sm reveal">Материалы к урокам <span class="accent">за 30 секунд</span> — с помощью ИИ</h1>
      <p class="rd-hero-sub reveal">Создавайте технологические карты, конспекты, рабочие листы, тесты, презентации и классные часы под свой предмет и класс. Или вставьте готовый материал — ИИ адаптирует его под ОВЗ, другой класс или требования ФОП-2026.</p>
      <div class="rd-hero-bullets reveal-stagger">
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Готовый файл сразу — скачивайте и используйте на уроке</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Соответствие ФОП, ФАОП ОВЗ и ФГОС 2026</div>
        <div class="rd-hb"><span class="check"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg></span>Экономит часы на подготовке к занятиям</div>
      </div>
      <div class="rd-hero-cta reveal">
        <a href="/material-generator/" class="rd-btn rd-btn-primary">Сгенерировать материал
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/material-adapter/" class="rd-btn rd-btn-ghost">Адаптировать свой материал</a>
      </div>
      <?php if ($userId): ?>
        <p class="mat-hero-balance">Ваш баланс: <strong><?= number_format((int)$balance, 0, '', ' ') ?> токенов</strong> · <a href="/material-balance/">пополнить</a></p>
      <?php else: ?>
        <p class="mat-hero-balance"><a href="/vhod?return=<?= urlencode('/material-generator/') ?>">Зарегистрируйтесь</a> — подарим 100 токенов на первые материалы.</p>
      <?php endif; ?>
    </div>

    <div class="rd-hero-art rd-hero-art-cat reveal">
      <div class="rd-blob"></div>
      <div class="mat-hero-doc">
        <div class="mat-hero-doc-top"><span class="mat-doc-badge">DOCX</span> Технологическая карта урока</div>
        <div class="mat-hero-doc-lines"><span></span><span></span><span></span><span></span><span></span></div>
        <div class="mat-hero-doc-foot">Этапы · Цели · УУД · Рефлексия</div>
      </div>
      <div class="rd-float-card rd-fc-cat-2">
        <div class="rd-fc-icon">⚡</div>
        <div class="rd-fc-text"><div class="rd-fc-t">Готово за 30 сек.</div><div class="rd-fc-s">ИИ генерирует файл</div></div>
      </div>
    </div>
  </div>
</section>

<!-- USP-полоска -->
<div class="rd-usps">
  <div class="rd-wrap rd-usp-grid reveal-stagger">
    <div class="rd-usp"><div class="ic">🤖</div><div><div class="t">Генерация через ИИ</div><div class="s">7 типов материалов</div></div></div>
    <div class="rd-usp"><div class="ic">🔄</div><div><div class="t">Адаптация под класс</div><div class="s">ОВЗ · ФАОП · ФОП-2026</div></div></div>
    <div class="rd-usp"><div class="ic">📄</div><div><div class="t">DOCX · PDF · PPTX</div><div class="s">готово к печати</div></div></div>
    <div class="rd-usp"><div class="ic">⚡</div><div><div class="t">~30 секунд</div><div class="s">вместо часов работы</div></div></div>
  </div>
</div>

<!-- Боль → решение -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Зачем это нужно</div>
        <h2 class="rd-section-title">Подготовка к урокам отнимает вечера. ИИ возвращает их вам.</h2>
      </div>
      <p class="rd-section-sub">Техкарты, конспекты, тесты и презентации по новым требованиям ФОП — это часы рутины. Опишите тему и класс — получите готовый материал, который останется только проверить и распечатать.</p>
    </div>
    <div class="mat-pains reveal-stagger">
      <div class="mat-pain">
        <div class="mat-pain-no">Без генератора</div>
        <ul>
          <li>Часы на оформление техкарты под ФГОС</li>
          <li>Поиск формулировок УУД и этапов</li>
          <li>Переделка чужих материалов под свой класс вручную</li>
          <li>Отдельно — презентация, отдельно — рабочий лист</li>
        </ul>
      </div>
      <div class="mat-pain mat-pain-yes">
        <div class="mat-pain-no">С генератором ФГОС-практикума</div>
        <ul>
          <li>Заполнили 3–5 полей — получили готовый файл</li>
          <li>Структура и УУД уже по требованиям ФОП</li>
          <li>Адаптация чужого материала в один клик</li>
          <li>Любой формат: DOCX, PDF, PPTX</li>
        </ul>
      </div>
    </div>
  </div>
</section>

<!-- Витрина типов материалов -->
<section class="rd-section" style="background:var(--ink-50);">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Что можно создать</div>
        <h2 class="rd-section-title">7 типов материалов под любой урок</h2>
      </div>
      <p class="rd-section-sub">Выберите тип, укажите предмет, класс и тему — ИИ соберёт готовый документ. Стоимость указана в токенах.</p>
    </div>
    <div class="mat-types-grid reveal-stagger">
      <?php foreach ($types as $t): ?>
        <a href="/material-generator/<?= htmlspecialchars($t['slug'], ENT_QUOTES, 'UTF-8') ?>/" class="mat-type-card">
          <div class="mat-type-ic"><?= $typeEmoji[$t['slug']] ?? '📄' ?></div>
          <h3><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></h3>
          <p><?= htmlspecialchars($t['description'] ?? '', ENT_QUOTES, 'UTF-8') ?></p>
          <div class="mat-type-foot">
            <span class="mat-fmt"><?= strtoupper(htmlspecialchars($t['output_format'], ENT_QUOTES, 'UTF-8')) ?></span>
            <span class="mat-cost"><strong><?= (int)$t['token_cost_default'] ?></strong> токенов</span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="mat-center"><a href="/material-generator/" class="rd-btn rd-btn-primary">Открыть генератор
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
    </a></div>
  </div>
</section>

<!-- Как это работает -->
<section class="rd-path rd-section tight">
  <div class="rd-wrap">
    <div class="reveal">
      <div class="rd-eyebrow">Как это работает</div>
      <h2 class="rd-section-title">Три шага до готового материала</h2>
    </div>
    <div class="rd-steps reveal-stagger">
      <div class="rd-step">
        <div class="rd-step-n">1</div>
        <h4>Выберите тип</h4>
        <p>Техкарта, конспект, рабочий лист, тест, презентация, классный час или фрагмент КТП.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">2</div>
        <h4>Заполните поля</h4>
        <p>Предмет, класс, тему и особенности группы. 3–5 полей — без долгих настроек.</p>
      </div>
      <div class="rd-step">
        <div class="rd-step-n">3</div>
        <h4>Скачайте файл</h4>
        <p>ИИ соберёт документ за ~30 секунд. Готово к печати в DOCX, PDF или PPTX.</p>
      </div>
    </div>
  </div>
</section>

<!-- Блок адаптации -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="mat-adapt reveal">
      <div class="mat-adapt-text">
        <div class="rd-eyebrow">Адаптация материалов</div>
        <h2 class="rd-section-title">Уже есть материал? Адаптируйте его под свой класс</h2>
        <p>Вставьте конспект, рабочую программу или техкарту и опишите, что изменить: упростить под ОВЗ, переделать под другой класс, привести к ФОП-2026 или добавить УУД. ИИ перепишет за 15–30 секунд — результат можно скопировать сразу.</p>
        <div class="rd-hero-cta">
          <a href="/material-adapter/" class="rd-btn rd-btn-primary">Адаптировать материал
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
          </a>
        </div>
      </div>
      <div class="mat-adapt-chips">
        <span class="mat-chip">→ под лёгкую ЗПР, 2 класс</span>
        <span class="mat-chip">→ под ФОП-2026 + УУД</span>
        <span class="mat-chip">→ короче и проще</span>
        <span class="mat-chip">→ под ФАОП ОВЗ</span>
        <span class="mat-chip">→ с другого класса на свой</span>
      </div>
    </div>
  </div>
</section>

<!-- Тарифы токенов -->
<section class="rd-section" style="background:var(--ink-50);">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Тарифы</div>
        <h2 class="rd-section-title">Платите токенами — только за то, что создаёте</h2>
      </div>
      <p class="rd-section-sub">При регистрации дарим 100 токенов. Дальше пополняйте баланс пакетами — токены не сгорают.</p>
    </div>
    <div class="mat-packs reveal-stagger">
      <?php foreach ($packages as $i => $p):
          $totalTokens = (int)$p['tokens'] + (int)$p['bonus_tokens'];
          $perToken = $totalTokens > 0 ? (float)$p['price_rub'] / $totalTokens : 0;
          $featured = ($i === 1);
      ?>
        <div class="mat-pack<?= $featured ? ' mat-pack-featured' : '' ?>">
          <?php if ($featured): ?><div class="mat-pack-tag">Выгодно</div><?php endif; ?>
          <h3><?= htmlspecialchars($p['name'], ENT_QUOTES, 'UTF-8') ?></h3>
          <div class="mat-pack-tokens"><?= number_format($totalTokens, 0, '', ' ') ?> <span>токенов</span></div>
          <?php if ((int)$p['bonus_tokens'] > 0): ?>
            <div class="mat-pack-bonus">+<?= (int)$p['bonus_tokens'] ?> бонусных</div>
          <?php endif; ?>
          <?php if (!empty($p['description'])): ?>
            <p class="mat-pack-desc"><?= htmlspecialchars($p['description'], ENT_QUOTES, 'UTF-8') ?></p>
          <?php endif; ?>
          <div class="mat-pack-price"><?= number_format((float)$p['price_rub'], 0, '', ' ') ?> ₽</div>
          <div class="mat-pack-per">~ <?= number_format($perToken, 2, ',', '') ?> ₽ за токен</div>
          <a href="/material-balance/" class="rd-btn <?= $featured ? 'rd-btn-primary' : 'rd-btn-ghost' ?>" style="width:100%;justify-content:center;">Купить</a>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- Trust band -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-trust-band reveal">
      <div class="rd-trust-head rd-section-head">
        <div>
          <div class="rd-eyebrow">Почему нам можно доверять</div>
          <h2 class="rd-section-title">Материалы под актуальные требования</h2>
        </div>
        <p class="rd-section-sub">Промпты выстроены под ФОП, ФАОП ОВЗ и ФГОС 2026. ФГОС-практикум — образовательная организация с лицензией и резидентством Сколково.</p>
      </div>
      <div class="rd-trust-cards reveal-stagger">
        <div class="rd-trust-card">
          <div class="badge">📚</div>
          <h4>По требованиям ФОП</h4>
          <p>Структура, цели и УУД — как требует обновлённый стандарт.</p>
        </div>
        <div class="rd-trust-card">
          <div class="badge">♿</div>
          <h4>Инклюзия и ОВЗ</h4>
          <p>Адаптация под ФАОП и особенности группы прямо в запросе.</p>
        </div>
        <div class="rd-trust-card">
          <div class="badge">⚡</div>
          <h4>Резидент Сколково</h4>
          <p>Образовательная лицензия № Л035-01212-59 и статус резидента.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<?php if (!empty($latest)): ?>
<!-- Витрина каталога -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="rd-section-head reveal">
      <div>
        <div class="rd-eyebrow">Готовые материалы</div>
        <h2 class="rd-section-title">Свежее в каталоге<?= $totalCount > 0 ? ' · ' . number_format($totalCount, 0, '', ' ') : '' ?></h2>
      </div>
      <p class="rd-section-sub">Берите готовое или используйте как основу для генерации похожего под свой класс.</p>
    </div>
    <div class="mat-cards-grid reveal-stagger">
      <?php foreach ($latest as $m): ?>
        <a href="/material/<?= htmlspecialchars($m['slug'], ENT_QUOTES, 'UTF-8') ?>/" class="mat-card">
          <?php if (!empty($m['type_name'])): ?>
            <div class="mat-card-type"><?= htmlspecialchars($m['type_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($m['file_format'])): ?> · <?= strtoupper(htmlspecialchars($m['file_format'], ENT_QUOTES, 'UTF-8')) ?><?php endif; ?></div>
          <?php endif; ?>
          <h3><?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?></h3>
          <?php if (!empty($m['description'])): ?>
            <p><?= htmlspecialchars(mb_substr($m['description'], 0, 100), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($m['description']) > 100 ? '…' : '' ?></p>
          <?php endif; ?>
          <div class="mat-card-foot">
            <span>↓ <?= (int)$m['downloads_count'] ?></span>
            <span><?= (int)$m['token_cost'] > 0 ? (int)$m['token_cost'] . ' токенов' : 'Бесплатно' ?></span>
          </div>
        </a>
      <?php endforeach; ?>
    </div>
    <div class="mat-center"><a href="/materialy/katalog/" class="rd-btn rd-btn-ghost">Весь каталог материалов</a></div>
  </div>
</section>
<?php endif; ?>

<!-- FAQ -->
<section class="rd-section">
  <div class="rd-wrap">
    <div class="rd-faq">
      <div class="reveal">
        <div class="rd-eyebrow">FAQ</div>
        <h2 class="rd-section-title">Частые вопросы</h2>
        <p class="rd-section-sub">Не нашли ответ? Напишите на <a href="mailto:info@fgos.pro" style="color:var(--indigo-600)">info@fgos.pro</a> или позвоните <a href="tel:+79223044413" style="color:var(--indigo-600)">+7 (922) 304-44-13</a>.</p>
      </div>
      <div class="rd-faq-list reveal-stagger">
        <div class="rd-faq-item">
          <button class="rd-faq-q">Что такое токены и сколько их нужно? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Токены — внутренняя валюта генератора. Один материал стоит 10–25 токенов в зависимости от типа. При регистрации мы дарим 100 токенов — этого хватает на 5–7 материалов. Дальше баланс пополняется пакетами.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Материалы соответствуют ФОП и ФГОС 2026? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Да. Промпты выстроены под требования ФОП, ФАОП ОВЗ и ФГОС 2026: корректная структура, цели, УУД и этапы. Перед использованием материал всегда стоит проверить — это черновик, который вы доводите под себя.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">В каком формате я получу материал? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Техкарты, конспекты, тесты, классные часы и КТП — в DOCX, рабочие листы — в PDF, презентации — в PPTX. Все файлы готовы к печати и редактированию.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Чем адаптация отличается от генерации? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Генерация создаёт материал с нуля по вашим параметрам. Адаптация берёт ваш готовый текст (чужой конспект, программу, техкарту) и переписывает его под нужные требования — например, упрощает под ОВЗ или переводит на другой класс.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Сколько времени занимает генерация? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Обычно 20–40 секунд. Адаптация готового материала — 15–30 секунд. Файл сразу появляется в вашем кабинете.</div></div>
        </div>
        <div class="rd-faq-item">
          <button class="rd-faq-q">Токены сгорают? <span class="pm">+</span></button>
          <div class="rd-faq-a"><div>Нет, купленные токены не имеют срока действия. Если генерация завершилась ошибкой, токены автоматически возвращаются на баланс.</div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- Финальный CTA -->
<section class="rd-section tight">
  <div class="rd-wrap">
    <div class="mat-final reveal">
      <h2>Соберите первый материал прямо сейчас</h2>
      <p>100 токенов в подарок при регистрации — хватит на несколько уроков.</p>
      <div class="rd-hero-cta" style="justify-content:center;">
        <a href="/material-generator/" class="rd-btn rd-btn-primary">Сгенерировать материал
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </a>
        <a href="/material-adapter/" class="rd-btn rd-btn-ghost">Адаптировать готовый</a>
      </div>
    </div>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

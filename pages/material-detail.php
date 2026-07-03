<?php
/**
 * Детальная страница материала — /material/{slug}/
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Material.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/session.php';

$materialObj = new Material($db);

$slug = $_GET['slug'] ?? '';
$material = $slug ? $materialObj->getBySlug($slug) : null;

// Непубличный материал (черновик/на модерации/отклонён) виден только автору и админу.
// Анонимное превью (user_id IS NULL) — владельцу текущей воронки по funnel_session cookie.
$currentUserId = $_SESSION['user_id'] ?? null;
$isAuthor = $material && $currentUserId && (int)$material['user_id'] === (int)$currentUserId;
$isAdmin  = isset($_SESSION['admin_id']);
$isPublished = $material && $material['status'] === 'published';
$fsidCookie = $_COOKIE['mat_fsid'] ?? '';
$isOwnerAnon = $material
    && $material['user_id'] === null
    && !empty($material['funnel_session_id'])
    && strlen($fsidCookie) === 32
    && $material['funnel_session_id'] === $fsidCookie;

// Заблокированное превью (сгенерировано, не оплачено) — показываем урезанную версию + paywall
$isLocked = $material && (int)$material['is_generated'] === 1 && (int)$material['is_unlocked'] === 0;

$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

if (!$material || (!$isPublished && !$isAuthor && !$isAdmin && !$isOwnerAnon)) {
    http_response_code(404);
    $pageTitle = 'Материал не найден — ' . SITE_NAME;
    $noindex = true;
    include __DIR__ . '/../includes/header-redesign.php';
    echo '<div class="rd-wrap" style="padding:80px 20px; text-align:center;">'
        . '<h1>404 — материал не найден</h1>'
        . '<p><a href="/materialy/katalog/" style="color:var(--indigo-600);">← В каталог материалов</a></p></div>';
    include __DIR__ . '/../includes/footer-redesign.php';
    exit;
}

// Просмотры считаем только для публичных материалов — превью черновика не накручивает счётчик.
if ($isPublished) {
    $materialObj->incrementViews((int)$material['id']);
}

$tags = $materialObj->getTags((int)$material['id']);

// Похожие материалы (перелинковка): тот же тип в приоритете, добор свежими
$relatedMaterials = $isPublished
    ? $materialObj->getRelated((int)$material['id'], (int)$material['material_type_id'], 4)
    : [];

$pageTitle       = ($material['meta_title'] ?: $material['title']) . ' | ' . SITE_NAME;
$pageDescription = $material['meta_description'] ?: mb_substr(strip_tags($material['description'] ?? ''), 0, 200);
$canonicalUrl    = SITE_URL . '/material/' . rawurlencode($material['slug']) . '/';

// Черновики/превью видны только владельцу — на всякий случай закрываем от индексации
if (!$isPublished) {
    $noindex = true;
}

// OG-картинка: превью материала, если есть
if (!empty($material['preview_image_url'])) {
    $ogImage = strpos($material['preview_image_url'], 'http') === 0
        ? $material['preview_image_url']
        : SITE_URL . $material['preview_image_url'];
}

// JSON-LD BreadcrumbList (выводится в header.php)
require_once __DIR__ . '/../includes/breadcrumb-jsonld-helper.php';
$breadcrumbJsonLd = buildBreadcrumbJsonLd([
    ['label' => 'Главная', 'url' => '/'],
    ['label' => 'Материалы ФОП', 'url' => '/materialy/'],
    ['label' => 'Каталог', 'url' => '/materialy/katalog/'],
    ['label' => $material['title']],
]);

// Преобразуем SET program_compliance в массив человекочитаемых меток
$programLabels = [
    'fop_do'    => 'ФОП ДО',
    'fop_noo'   => 'ФОП НОО',
    'fop_ooo'   => 'ФОП ООО',
    'fop_soo'   => 'ФОП СОО',
    'faop_ovz'  => 'ФАОП (ОВЗ)',
    'fgos_2021' => 'ФГОС 2021',
    'fgos_2026' => 'ФГОС 2026',
];
$programs = [];
if (!empty($material['program_compliance'])) {
    foreach (explode(',', $material['program_compliance']) as $code) {
        $code = trim($code);
        if (isset($programLabels[$code])) {
            $programs[] = $programLabels[$code];
        }
    }
}

// Реальный формат скачивания (для копий вместо хардкода «PDF»)
$formatLabels = ['pdf' => 'PDF', 'docx' => 'Word (DOCX)', 'pptx' => 'PowerPoint (PPTX)'];
$dlFormat = strtolower((string)($material['type_format'] ?? $material['file_format'] ?? 'pdf'));
$dlFormatLabel = $formatLabels[$dlFormat] ?? strtoupper($dlFormat);

// schema.org LearningResource — для AI Overviews/SGE и обычной поисковой выдачи
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'LearningResource',
    'name' => $material['title'],
    'description' => $pageDescription,
    'url' => $canonicalUrl,
    'inLanguage' => 'ru',
    'learningResourceType' => $material['type_name'] ?? 'Учебный материал',
    'isAccessibleForFree' => ((int)$material['token_cost'] === 0),
    'datePublished' => date('c', strtotime($material['published_at'] ?: $material['created_at'])),
    'dateModified' => date('c', strtotime($material['updated_at'] ?? $material['created_at'])),
    'audience' => [
        '@type' => 'EducationalAudience',
        'educationalRole' => 'teacher',
    ],
    'interactionStatistic' => [
        '@type' => 'InteractionCounter',
        'interactionType' => 'https://schema.org/DownloadAction',
        'userInteractionCount' => (int)$material['downloads_count'],
    ],
    'publisher' => [
        '@type' => 'Organization',
        'name' => defined('SITE_NAME') ? SITE_NAME : 'fgos.pro',
        'url' => SITE_URL,
    ],
];
if (!empty($tags)) {
    $schema['keywords'] = implode(', ', array_column($tags, 'name'));
}
if (!empty($ogImage)) {
    $schema['image'] = $ogImage;
}
if (!empty($programs)) {
    $schema['educationalAlignment'] = array_map(fn($p) => [
        '@type' => 'AlignmentObject',
        'alignmentType' => 'educationalSubject',
        'targetName' => $p,
    ], $programs);
}
// Отзывы продукта + микроразметка рейтинга (aggregateRating/review)
require_once __DIR__ . '/../classes/Review.php';
require_once __DIR__ . '/../includes/review-schema-helper.php';
$reviewEntityType = 'material';
$reviewEntityId   = (int)$material['id'];
$reviewObj   = new Review($db);
$reviewStats = $reviewObj->getStats($reviewEntityType, $reviewEntityId);
$reviewList  = $reviewObj->getApproved($reviewEntityType, $reviewEntityId, 20);
require_once __DIR__ . '/../includes/rating-synthetic-helper.php';
$reviewSeedKey = $reviewEntityType . ':' . $reviewEntityId;
$schema['sku'] = syntheticSku($reviewSeedKey);
$schema = applyReviewSchema($schema, $reviewStats, $reviewList, $reviewSeedKey);
$additionalCSS[] = '/assets/css/reviews.css?v=' . filemtime(__DIR__ . '/../assets/css/reviews.css');
$additionalJS = $additionalJS ?? [];
$additionalJS[] = '/assets/js/reviews.js?v=' . filemtime(__DIR__ . '/../assets/js/reviews.js');

$schemaJson = json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

include __DIR__ . '/../includes/header-redesign.php';
?>

<script type="application/ld+json"><?= $schemaJson ?></script>

<section class="mat-page">
  <div class="rd-wrap mat-detail">
    <div class="rd-crumbs" style="margin-bottom:16px;">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/materialy/">Материалы ФОП</a>
      <span class="sep">/</span>
      <a href="/materialy/katalog/">Каталог</a>
      <span class="sep">/</span>
      <strong><?= htmlspecialchars(mb_strlen($material['title']) > 50 ? mb_substr($material['title'], 0, 50) . '…' : $material['title'], ENT_QUOTES, 'UTF-8') ?></strong>
    </div>

    <?php if (!empty($material['type_name'])): ?>
      <div class="mat-detail-type">
        <?= htmlspecialchars($material['type_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($material['file_format'])): ?> · <?= strtoupper(htmlspecialchars($material['file_format'], ENT_QUOTES, 'UTF-8')) ?><?php endif; ?>
      </div>
    <?php endif; ?>

    <h1><?= htmlspecialchars($material['title'], ENT_QUOTES, 'UTF-8') ?></h1>

    <?php if (!empty($programs)): ?>
      <div class="mat-detail-tags">
        <?php foreach ($programs as $label): ?>
          <span class="mat-detail-tag"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($material['preview_image_url'])): ?>
      <img src="<?= htmlspecialchars($material['preview_image_url'], ENT_QUOTES, 'UTF-8') ?>"
           alt="" style="width:100%; max-width:600px; border-radius:14px; margin: 16px 0;">
    <?php endif; ?>

    <?php if (!empty($material['description'])): ?>
      <p class="mat-detail-desc"><?= nl2br(htmlspecialchars($material['description'], ENT_QUOTES, 'UTF-8')) ?></p>
    <?php endif; ?>

    <?php if ($isLocked): ?>
      <?php
        // Урезанное превью: только текстовый отрывок (полный форматированный материал — за оплату).
        $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$material['content'])));
        $excerpt = mb_substr($excerpt, 0, 600);
        $unlockCost = (int)$material['unlock_token_cost'];
        $csrfToken = generateCSRFToken();
        $bonus = UserTokens::signupBonus();
        $balance = $currentUserId ? (new UserTokens($db))->getBalance((int)$currentUserId) : null;
      ?>
      <div class="mat-detail-content mat-locked-preview" style="position:relative; max-height:320px; overflow:hidden;">
        <p><?= nl2br(htmlspecialchars($excerpt, ENT_QUOTES, 'UTF-8')) ?>…</p>
        <div style="position:absolute; inset:auto 0 0 0; height:160px; background:linear-gradient(to bottom, rgba(255,255,255,0), #fff);"></div>
      </div>

      <div class="mat-paywall" style="border:1px solid #e2e8f0; border-radius:16px; padding:24px; margin:20px 0; text-align:center; background:#f8fafc;">
        <?php if (!empty($_GET['paid'])): ?>
          <div class="mat-notice" style="background:#e7f5e7;border-color:#a7d8a7;margin-bottom:16px;">Оплата принята — нажмите «Скачать». Если токены ещё не зачислились, обновите страницу через минуту.</div>
        <?php endif; ?>
        <h3 style="margin:0 0 8px;">Материал готов 🎉</h3>
        <p style="margin:0 0 16px; color:#475569;">Скачайте полную версию (<?= htmlspecialchars($dlFormatLabel, ENT_QUOTES, 'UTF-8') ?>) — готово к печати и редактированию.</p>
        <button type="button" id="unlock-btn" class="rd-btn rd-btn-primary" data-material="<?= (int)$material['id'] ?>">
          Скачать за <?= $unlockCost ?> токенов
        </button>
        <?php if ($currentUserId): ?>
          <p style="margin:12px 0 0; font-size:14px; color:#64748b;">Ваш баланс: <strong><?= number_format((int)$balance, 0, '', ' ') ?></strong> токенов<?php if ((int)$balance < $unlockCost): ?> · <a href="/material-balance/">пополнить</a><?php endif; ?></p>
        <?php else: ?>
          <p style="margin:12px 0 0; font-size:14px; color:#64748b;">Первый материал бесплатно — дарим <?= $bonus ?> токенов при регистрации.</p>
        <?php endif; ?>
        <div id="unlock-error" class="mat-error" style="display:none; margin-top:12px;"></div>
      </div>

      <!-- Модалка быстрой регистрации (для анонима перед оплатой) -->
      <div id="reg-modal" style="display:none; position:fixed; inset:0; background:rgba(15,23,42,.55); z-index:1000; align-items:center; justify-content:center; padding:20px;">
        <div style="background:#fff; border-radius:16px; max-width:420px; width:100%; padding:28px; box-shadow:0 20px 60px rgba(0,0,0,.25);">
          <h3 style="margin:0 0 6px;">Куда сохранить материал?</h3>
          <p style="margin:0 0 18px; color:#64748b; font-size:14px;">Первый материал бесплатно. Дарим <?= $bonus ?> токенов на старт.</p>
          <div class="mat-field"><label>Email <span class="req">*</span></label><input type="email" id="reg-email" placeholder="example@mail.ru" required></div>
          <div class="mat-field"><label>ФИО <span class="req">*</span></label><input type="text" id="reg-name" placeholder="Иванов Иван Иванович" required></div>
          <label class="checkbox-label" style="display:flex; gap:8px; align-items:flex-start; font-size:13px; margin:10px 0 16px;">
            <input type="checkbox" id="reg-agree">
            <span>Принимаю <a href="/pages/terms.php" target="_blank">условия</a> и <a href="/pages/privacy.php" target="_blank">политику конфиденциальности</a></span>
          </label>
          <div id="reg-error" class="mat-error" style="display:none; margin-bottom:12px;"></div>
          <button type="button" id="reg-submit" class="rd-btn rd-btn-primary" style="width:100%;">Продолжить</button>
          <button type="button" id="reg-cancel" class="rd-btn rd-btn-ghost" style="width:100%; margin-top:8px;">Отмена</button>
        </div>
      </div>

      <script>
      (function () {
          var csrf = <?= json_encode($csrfToken) ?>;
          var materialId = <?= (int)$material['id'] ?>;
          var isLoggedIn = <?= $currentUserId ? 'true' : 'false' ?>;
          var unlockBtn = document.getElementById('unlock-btn');
          var unlockError = document.getElementById('unlock-error');
          var modal = document.getElementById('reg-modal');
          var regError = document.getElementById('reg-error');
          var regSubmit = document.getElementById('reg-submit');

          function showErr(el, msg) { el.innerHTML = msg; el.style.display = 'block'; }

          function doUnlock() {
              unlockError.style.display = 'none';
              unlockBtn.disabled = true;
              var fd = new FormData();
              fd.append('csrf', csrf);
              fd.append('material_id', materialId);
              fetch('/ajax/unlock-material.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (d) {
                      unlockBtn.disabled = false;
                      if (d && d.success) {
                          // Цель-конверсия: материал разблокирован (оплаченная генерация)
                          if (typeof ym === 'function') { ym(106465857, 'reachGoal', 'material_unlocked'); }
                          window.location.href = d.download_url;
                          return;
                      }
                      if (d && d.code === 'unauthorized') { modal.style.display = 'flex'; return; }
                      var msg = (d && d.error) ? d.error : 'Не удалось разблокировать';
                      if (d && d.code === 'not_enough_tokens' && d.buy_url) {
                          msg += ' <a href="' + d.buy_url + '">Пополнить →</a>';
                      }
                      showErr(unlockError, msg);
                  })
                  .catch(function () { unlockBtn.disabled = false; showErr(unlockError, 'Сеть прервалась. Попробуйте ещё раз.'); });
          }

          unlockBtn.addEventListener('click', function () {
              if (isLoggedIn) { doUnlock(); } else { modal.style.display = 'flex'; }
          });
          document.getElementById('reg-cancel').addEventListener('click', function () { modal.style.display = 'none'; });

          regSubmit.addEventListener('click', function () {
              regError.style.display = 'none';
              var email = document.getElementById('reg-email').value.trim();
              var name = document.getElementById('reg-name').value.trim();
              var agree = document.getElementById('reg-agree').checked;
              if (!email || !name || !agree) { showErr(regError, 'Заполните email, ФИО и примите условия'); return; }
              regSubmit.disabled = true;
              var fd = new FormData();
              fd.append('csrf', csrf); fd.append('email', email); fd.append('full_name', name); fd.append('agreement', '1');
              fetch('/ajax/quick-register.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                  .then(function (r) { return r.json(); })
                  .then(function (d) {
                      regSubmit.disabled = false;
                      if (d && d.success) { isLoggedIn = true; modal.style.display = 'none'; doUnlock(); }
                      else { showErr(regError, (d && d.error) ? d.error : 'Ошибка регистрации'); }
                  })
                  .catch(function () { regSubmit.disabled = false; showErr(regError, 'Сеть прервалась. Попробуйте ещё раз.'); });
          });
      })();
      </script>

    <?php else: ?>
      <?php if (!empty($material['content'])): ?>
        <div class="mat-detail-content"><?= $material['content'] /* HTML, доверенный из админки/ИИ-генератора */ ?></div>
      <?php endif; ?>

      <div class="mat-download">
        <div class="dl-count">↓ <?= (int)$material['downloads_count'] ?> скачиваний</div>
        <div class="dl-cost">
          <?php if ((int)$material['token_cost'] > 0): ?>
            Скачивание: <strong><?= (int)$material['token_cost'] ?> токенов</strong>
          <?php else: ?>
            <strong>Скачать бесплатно</strong>
          <?php endif; ?>
        </div>
        <a href="/material-download.php?id=<?= (int)$material['id'] ?>" class="rd-btn rd-btn-primary" style="background:#fff;color:var(--indigo-700,#1a2f8a);">
          Скачать (<?= htmlspecialchars($dlFormatLabel, ENT_QUOTES, 'UTF-8') ?>)
        </a>
      </div>
    <?php endif; ?>

    <?php if (!empty($material['type_slug'])): ?>
      <div class="mat-similar">
        <h3>Нужен похожий материал под ваш класс?</h3>
        <p>Сгенерируйте свой за 30 секунд через ИИ.</p>
        <a href="/material-generator/<?= htmlspecialchars($material['type_slug'], ENT_QUOTES, 'UTF-8') ?>/" class="rd-btn rd-btn-primary">Сгенерировать похожий →</a>
      </div>
    <?php endif; ?>

    <?php if (!empty($tags)): ?>
      <div class="mat-detail-tags" style="margin:24px 0;">
        <?php foreach ($tags as $tag): ?>
          <a href="/materialy/katalog/?tag=<?= htmlspecialchars($tag['slug'], ENT_QUOTES, 'UTF-8') ?>" class="mat-detail-tag">#<?= htmlspecialchars($tag['name'], ENT_QUOTES, 'UTF-8') ?></a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($relatedMaterials)): ?>
      <div class="mat-related" style="margin-top:40px;">
        <h2 style="font-size:22px; margin:0 0 16px;">Похожие материалы</h2>
        <div class="mat-cards-grid">
          <?php foreach ($relatedMaterials as $rm): ?>
            <a href="/material/<?= htmlspecialchars($rm['slug'], ENT_QUOTES, 'UTF-8') ?>/" class="mat-card">
              <?php if (!empty($rm['type_name'])): ?>
                <div class="mat-card-type">
                  <?= htmlspecialchars($rm['type_name'], ENT_QUOTES, 'UTF-8') ?><?php if (!empty($rm['file_format'])): ?> · <?= strtoupper(htmlspecialchars($rm['file_format'], ENT_QUOTES, 'UTF-8')) ?><?php endif; ?>
                </div>
              <?php endif; ?>
              <h3><?= htmlspecialchars($rm['title'], ENT_QUOTES, 'UTF-8') ?></h3>
              <?php if (!empty($rm['description'])): ?>
                <p><?= htmlspecialchars(mb_substr($rm['description'], 0, 120), ENT_QUOTES, 'UTF-8') ?><?= mb_strlen($rm['description']) > 120 ? '…' : '' ?></p>
              <?php endif; ?>
              <div class="mat-card-foot">
                <span>↓ <?= (int)$rm['downloads_count'] ?></span>
                <span><?= (int)$rm['token_cost'] > 0 ? (int)$rm['token_cost'] . ' токенов' : 'Бесплатно' ?></span>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <p style="margin-top:16px;"><a href="/materialy/katalog/" style="color:var(--indigo-600); font-weight:600;">Все материалы в каталоге →</a></p>
      </div>
    <?php endif; ?>
  </div>
</section>

<?php if ($isPublished) { include __DIR__ . '/../includes/review-section.php'; } ?>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

<?php
/**
 * Лендинг ИИ-генератора материалов — /material-generator/
 * 7 карточек типов материалов.
 */

session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/MaterialType.php';
require_once __DIR__ . '/../classes/UserTokens.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/material-tracking.php';

trackMaterialVisit($db, '/material-generator/');

$typeObj = new MaterialType($db);
$types = $typeObj->getWithCounts();

$userId = $_SESSION['user_id'] ?? null;
$balance = null;
if ($userId) {
    $tokens = new UserTokens($db);
    // Идемпотентный стартовый бонус — выдаётся при первом заходе на генератор
    $tokens->grantSignupBonusIfNeeded((int)$userId);
    // Месячный грант токенов подписчику Базового тарифа (идемпотентно по слоту периода).
    require_once __DIR__ . '/../classes/SubscriptionService.php';
    (new SubscriptionService($db))->grantMonthlyTokensIfDue((int)$userId);
    $balance = $tokens->getBalance((int)$userId);
}

$typeEmoji = [
    'tehkarta-uroka'   => '🗺️',
    'konspekt-uroka'   => '📝',
    'rabochiy-list'    => '📋',
    'test-kontrolnaya' => '✅',
    'prezentatsiya'    => '📊',
    'klassnyy-chas'    => '👥',
    'ktp-fragment'     => '📅',
];

$pageTitle = 'ИИ-генератор материалов ФОП — техкарта, конспект, тест, презентация за 30 секунд | ' . SITE_NAME;
$pageDescription = 'Сгенерируйте технологическую карту урока, конспект, рабочий лист, тест, презентацию, классный час или КТП через ИИ за 30 секунд. Под ФОП и ФАОП ОВЗ.';
$canonicalUrl = SITE_URL . '/material-generator/';
$rdActivePage = 'materialy';
$additionalCSS = ['/assets/css/materials.css?v=' . filemtime(__DIR__ . '/../assets/css/materials.css')];

include __DIR__ . '/../includes/header-redesign.php';
?>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <div class="rd-crumbs">
      <a href="/">Главная</a>
      <span class="sep">/</span>
      <a href="/materialy/">Материалы ФОП</a>
      <span class="sep">/</span>
      <strong>Генератор</strong>
    </div>
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:18px;">ИИ-генератор материалов ФОП</h1>
    <p class="rd-hero-sub" style="max-width:680px;">Выберите тип материала, заполните 3–5 полей — получите готовый файл за 30 секунд. Соответствие ФОП и ФАОП ОВЗ. Скачивание в PDF, DOCX или PPTX.</p>

    <?php if ($userId): ?>
      <div class="mat-balance-pill">Ваш баланс: <strong><?= number_format((int)$balance, 0, '', ' ') ?> токенов</strong> · <a href="/material-balance/">пополнить</a></div>
    <?php else: ?>
      <div class="mat-notice">Выберите тип материала — <strong>первый бесплатно</strong>. Дарим <strong><?= UserTokens::signupBonus() ?> токенов</strong> на старт, регистрация прямо в форме.</div>
    <?php endif; ?>
  </div>
</section>

<section class="mat-page">
  <div class="rd-wrap">
    <div class="mat-types-grid">
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
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

<?php
/**
 * Лендинг подписки — /podpiska/
 *
 * Две карточки тарифов (Базовый / Про), переключатель месяц/год.
 * Кнопка оформления создаёт Yookassa-платёж через ajax/create-subscription-payment.php.
 * Подписка АДДИТИВНА: разовые покупки дипломов/сертификатов остаются доступны всем.
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/SubscriptionService.php';
require_once __DIR__ . '/../includes/session.php';

$dbh = new Database($db);
$plans = $dbh->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY sort_order ASC");

$userId = $_SESSION['user_id'] ?? null;
$activeSub = $userId ? (new SubscriptionService($db))->getActiveSubscription((int)$userId) : null;

$pageTitle = 'Подписка для педагога — все документы и материалы | ' . SITE_NAME;
$pageDescription = 'Подписка fgos.pro: безлимит дипломов и сертификатов для портфолио аттестации, генератор материалов ФОП и скидка на курсы.';
$canonicalUrl = SITE_URL . '/podpiska/';
$rdActivePage = 'podpiska';

include __DIR__ . '/../includes/header-redesign.php';
?>

<style>
.sub-wrap{max-width:1000px;margin:0 auto;padding:0 16px;}
.sub-active-banner{background:#eafbf0;border:1px solid #b7ecc8;border-radius:14px;padding:16px 20px;margin:18px 0;color:#13703a;}
</style>

<section class="rd-hero-catalog">
  <div class="rd-wrap">
    <h1 class="rd-hero-title rd-hero-title-sm" style="margin-top:10px;">Подписка для педагога</h1>
    <p style="color:#5b6178;max-width:640px;margin-top:10px;font-size:17px;">
      Все документы для портфолио аттестации без доплат и материалы ФОП в одной подписке.
      Разовые покупки остаются доступны — подписка просто выгоднее, если вы оформляете
      больше двух документов в год.
    </p>
  </div>
</section>

<section style="padding:30px 0 60px;">
  <div class="sub-wrap">

    <?php if ($activeSub): ?>
      <div class="sub-active-banner">
        У вас активна подписка <strong><?= htmlspecialchars($activeSub['plan_name'], ENT_QUOTES, 'UTF-8') ?></strong>
        до <strong><?= htmlspecialchars(date('d.m.Y', strtotime($activeSub['expires_at'])), ENT_QUOTES, 'UTF-8') ?></strong>.
        Управление — в <a href="/pages/cabinet.php">личном кабинете</a>.
      </div>
    <?php endif; ?>

    <?php include __DIR__ . '/../includes/subscription-plans.php'; ?>
  </div>
</section>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

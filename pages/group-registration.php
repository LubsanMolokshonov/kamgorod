<?php
/**
 * Group Registration Page
 * Оформление дипломов на группу/класс (2–30 участников) для конкурса или олимпиады.
 * Опциональный путь — не заменяет одиночный флоу.
 */

session_start();
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../classes/Olympiad.php';
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/group-pricing.php';

$productType = ($_GET['product_type'] ?? '') === 'olympiad' ? 'olympiad' : 'competition';
$productId   = (int)($_GET['product_id'] ?? 0);

if ($productId <= 0) {
    header('Location: /');
    exit;
}

$nominations = [];
if ($productType === 'olympiad') {
    $olympiadObj = new Olympiad($db);
    $product = $olympiadObj->getById($productId);
    if (!$product) { header('Location: /olimpiady'); exit; }
    $productTitle = $product['title'];
    $unitPrice = (int)($product['diploma_price'] ?? OLYMPIAD_DIPLOMA_PRICE);
    $backUrl = '/olimpiady';
    $placementOptions = ['1' => '1 место', '2' => '2 место', '3' => '3 место'];
} else {
    $competitionObj = new Competition($db);
    $product = $competitionObj->getById($productId);
    if (!$product) { header('Location: /konkursy'); exit; }
    $productTitle = $product['title'];
    $unitPrice = (int)$product['price'];
    $backUrl = '/konkursy';
    $nominations = $competitionObj->getNominationOptions($productId);
    $placementOptions = ['1' => '1 место', '2' => '2 место', '3' => '3 место', 'участник' => 'Участник'];
}

// Шаблоны диплома
$templates = $db->query(
    "SELECT * FROM diploma_templates WHERE is_active = 1 AND type = 'participant' ORDER BY display_order ASC"
)->fetchAll(PDO::FETCH_ASSOC);

// Предзаполнение данных учителя
$userData = [];
if (isset($_SESSION['user_id'])) {
    $userStmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $userStmt->execute([$_SESSION['user_id']]);
    $userData = $userStmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

$tiers = groupDiscountTiers();

$pageTitle = 'Групповое участие: ' . htmlspecialchars($productTitle) . ' | ' . SITE_NAME;
$pageDescription = 'Оформите дипломы сразу на группу или весь класс со скидкой по объёму';
$noindex = true;

include __DIR__ . '/../includes/header-redesign.php';
?>

<main>
<section class="rd-section">
  <div class="rd-wrap" style="max-width:900px;">
    <div class="cd-crumbs" style="margin-bottom:20px;">
      <a href="/">Главная</a><span class="sep">/</span>
      <a href="<?php echo $backUrl; ?>"><?php echo $productType === 'olympiad' ? 'Олимпиады' : 'Конкурсы'; ?></a>
      <span class="sep">/</span><strong>Групповое участие</strong>
    </div>

    <h1 style="font:700 clamp(26px,3vw,38px) var(--font-sans);letter-spacing:-.02em;color:var(--ink-900);margin:0 0 6px;">
      Оформить на группу / весь класс
    </h1>
    <p style="color:var(--ink-500);font-size:16px;margin:0 0 8px;"><?php echo htmlspecialchars($productTitle); ?></p>

    <div class="grp-tiers">
      <?php foreach ($tiers as $t): ?>
        <span class="grp-tier-pill"><?php echo (int)$t['min']; ?>–<?php echo (int)$t['max']; ?> чел. — −<?php echo (int)$t['percent']; ?>%</span>
      <?php endforeach; ?>
    </div>

    <form id="groupForm" method="POST"
          data-unit-price="<?php echo $unitPrice; ?>"
          data-tiers='<?php echo htmlspecialchars(json_encode($tiers), ENT_QUOTES, 'UTF-8'); ?>'
          data-max="<?php echo GROUP_MAX_PARTICIPANTS; ?>"
          data-min="<?php echo GROUP_MIN_PARTICIPANTS; ?>"
          data-product-type="<?php echo $productType; ?>">
      <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
      <input type="hidden" name="product_type" value="<?php echo $productType; ?>">
      <input type="hidden" name="product_id" value="<?php echo $productId; ?>">
      <input type="hidden" name="visit_id" id="grpVisitId" value="">
      <input type="hidden" name="utm_source" value=""><input type="hidden" name="utm_medium" value="">
      <input type="hidden" name="utm_campaign" value=""><input type="hidden" name="utm_content" value="">
      <input type="hidden" name="utm_term" value="">

      <!-- Общие поля -->
      <fieldset class="grp-card">
        <legend>Общие данные (для всех участников)</legend>
        <div class="grp-grid">
          <label>Email учителя *
            <input type="email" name="email" required value="<?php echo htmlspecialchars($userData['email'] ?? ''); ?>">
          </label>
          <label>ФИО руководителя (учителя)
            <input type="text" name="supervisor_name" maxlength="55" value="<?php echo htmlspecialchars($userData['full_name'] ?? ''); ?>" placeholder="Иванова Мария Петровна">
          </label>
          <label>Образовательное учреждение *
            <input type="text" name="organization" required value="<?php echo htmlspecialchars($userData['organization'] ?? ''); ?>">
          </label>
          <label>Населённый пункт
            <input type="text" name="city" value="<?php echo htmlspecialchars($userData['city'] ?? ''); ?>">
          </label>
          <label>Дата участия *
            <input type="date" name="participation_date" required value="<?php echo date('Y-m-d'); ?>">
          </label>
          <label><?php echo $productType === 'olympiad' ? 'Уровень' : 'Тип конкурса'; ?>
            <select name="competition_type">
              <?php if ($productType === 'olympiad'): ?>
                <option value="всероссийская">Всероссийская</option>
                <option value="международная">Международная</option>
                <option value="межрегиональная">Межрегиональная</option>
              <?php else: ?>
                <option value="всероссийский">Всероссийский</option>
                <option value="международный">Международный</option>
                <option value="межрегиональный">Межрегиональный</option>
              <?php endif; ?>
            </select>
          </label>
          <?php if ($productType === 'competition' && !empty($nominations)): ?>
          <label>Номинация *
            <select name="nomination" required>
              <?php foreach ($nominations as $nom): ?>
                <option value="<?php echo htmlspecialchars($nom); ?>"><?php echo htmlspecialchars($nom); ?></option>
              <?php endforeach; ?>
            </select>
          </label>
          <?php elseif ($productType === 'competition'): ?>
          <label>Номинация *
            <input type="text" name="nomination" required placeholder="Например: Лучшая методическая разработка">
          </label>
          <?php endif; ?>
        </div>

        <div class="grp-templates">
          <span class="grp-tpl-label">Шаблон диплома *</span>
          <div class="grp-tpl-row">
            <?php foreach ($templates as $i => $tpl): ?>
              <label class="grp-tpl">
                <input type="radio" name="template_id" value="<?php echo (int)$tpl['id']; ?>" <?php echo $i === 0 ? 'checked' : ''; ?>>
                <span><?php echo htmlspecialchars($tpl['name'] ?? ('Шаблон ' . ((int)$tpl['id']))); ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </fieldset>

      <!-- Ростер участников -->
      <fieldset class="grp-card">
        <legend>Список участников</legend>
        <table class="grp-table" id="grpTable">
          <thead>
            <tr>
              <th style="width:32px;">#</th>
              <th>ФИО участника *</th>
              <th style="width:150px;">Место / степень</th>
              <?php if ($productType === 'competition'): ?><th>Название работы</th><?php endif; ?>
              <th style="width:40px;"></th>
            </tr>
          </thead>
          <tbody id="grpRows"></tbody>
        </table>
        <button type="button" class="rd-btn rd-btn-ghost" id="grpAddRow">+ ещё участник</button>
        <p class="grp-hint" id="grpLimitHint" style="display:none;">Достигнут максимум — <?php echo GROUP_MAX_PARTICIPANTS; ?> участников.</p>
      </fieldset>

      <!-- Превью цены -->
      <div class="grp-summary">
        <div class="grp-sum-line"><span>Участников:</span> <strong id="grpCount">0</strong></div>
        <div class="grp-sum-line"><span>Цена за диплом:</span> <strong><?php echo number_format($unitPrice, 0, ',', ' '); ?> ₽</strong></div>
        <div class="grp-sum-line" id="grpDiscLine" style="display:none;"><span>Скидка группы:</span> <strong id="grpDiscount">−0 ₽ (0%)</strong></div>
        <div class="grp-sum-total"><span>Итого:</span> <strong id="grpTotal">0 ₽</strong></div>
        <button type="submit" class="rd-btn rd-btn-primary" id="grpSubmit" disabled>
          Добавить в корзину и перейти к оплате
        </button>
        <p class="grp-hint">Дипломы сформируются автоматически после оплаты и появятся в личном кабинете.</p>
      </div>
    </form>

    <!-- Шаблон строки участника -->
    <template id="grpRowTpl">
      <tr class="grp-row">
        <td class="grp-num"></td>
        <td><input type="text" name="participants[__I__][fio]" maxlength="55" class="grp-fio" placeholder="Фамилия Имя"></td>
        <td>
          <select name="participants[__I__][placement]">
            <?php foreach ($placementOptions as $val => $label): ?>
              <option value="<?php echo htmlspecialchars($val); ?>"<?php echo $val === 'участник' || $val === '3' ? '' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
            <?php endforeach; ?>
          </select>
        </td>
        <?php if ($productType === 'competition'): ?>
        <td><input type="text" name="participants[__I__][work_title]" maxlength="255" placeholder="(необязательно)"></td>
        <?php endif; ?>
        <td><button type="button" class="grp-del" title="Удалить">×</button></td>
      </tr>
    </template>
  </div>
</section>
</main>

<style>
.grp-tiers{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 24px;}
.grp-tier-pill{background:var(--indigo-50,#eef1ff);color:var(--indigo-700,#3a45c7);font-weight:600;font-size:13px;padding:6px 12px;border-radius:999px;}
.grp-card{border:1px solid var(--line-200,#e6e8f0);border-radius:16px;padding:22px;margin:0 0 20px;}
.grp-card legend{font:700 17px var(--font-sans);color:var(--ink-900);padding:0 8px;}
.grp-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.grp-grid label,.grp-templates .grp-tpl-label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:var(--ink-700,#444);}
.grp-grid input,.grp-grid select{padding:10px 12px;border:1px solid var(--line-300,#d4d8e3);border-radius:10px;font-size:15px;font-weight:400;}
.grp-templates{margin-top:16px;}
.grp-tpl-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:8px;}
.grp-tpl{display:flex;align-items:center;gap:6px;font-weight:500;font-size:14px;border:1px solid var(--line-300,#d4d8e3);border-radius:10px;padding:8px 12px;cursor:pointer;}
.grp-table{width:100%;border-collapse:collapse;margin-bottom:14px;}
.grp-table th{text-align:left;font-size:12px;text-transform:uppercase;letter-spacing:.03em;color:var(--ink-500,#777);padding:6px 8px;border-bottom:1px solid var(--line-200,#e6e8f0);}
.grp-table td{padding:6px 8px;vertical-align:middle;}
.grp-table input,.grp-table select{width:100%;padding:8px 10px;border:1px solid var(--line-300,#d4d8e3);border-radius:8px;font-size:14px;}
.grp-num{color:var(--ink-500,#777);font-weight:600;text-align:center;}
.grp-del{background:none;border:none;color:#c0392b;font-size:22px;line-height:1;cursor:pointer;padding:0 6px;}
.grp-hint{font-size:13px;color:var(--ink-500,#777);margin:8px 0 0;}
.grp-summary{position:sticky;bottom:0;background:#fff;border:1px solid var(--line-200,#e6e8f0);border-radius:16px;padding:20px;box-shadow:0 -4px 20px rgba(0,0,0,.04);}
.grp-sum-line{display:flex;justify-content:space-between;font-size:15px;color:var(--ink-700,#444);margin-bottom:6px;}
.grp-sum-total{display:flex;justify-content:space-between;font:700 20px var(--font-sans);color:var(--ink-900);margin:10px 0 16px;padding-top:10px;border-top:1px solid var(--line-200,#e6e8f0);}
.grp-summary .rd-btn{width:100%;justify-content:center;}
@media(max-width:640px){.grp-grid{grid-template-columns:1fr;}}
</style>

<script src="/assets/js/group-registration.js?v=<?php echo @filemtime(__DIR__ . '/../assets/js/group-registration.js'); ?>"></script>

<?php include __DIR__ . '/../includes/footer-redesign.php'; ?>

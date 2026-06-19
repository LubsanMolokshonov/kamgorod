<?php
require_once __DIR__ . '/../includes/auth.php'; // admin auth guard + CSRF helpers
/**
 * Тарифы подписки — редактирование цен, лимитов токенов, скидки на курсы, активности.
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../classes/Database.php';

$pageTitle = 'Тарифы подписки';
$dbh = new Database($db);
$flash = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken($_POST['csrf'] ?? '')) {
        $flash = ['type' => 'error', 'msg' => 'Сессия истекла, повторите.'];
    } else {
        $planId = (int)($_POST['plan_id'] ?? 0);
        // monthly_generation_tokens: пусто = безлимит (NULL)
        $tokensRaw = trim((string)($_POST['monthly_generation_tokens'] ?? ''));
        $tokens = $tokensRaw === '' ? null : max(0, (int)$tokensRaw);
        $data = [
            'name'                    => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 120),
            'price_monthly'           => max(0, (float)($_POST['price_monthly'] ?? 0)),
            'price_yearly'            => max(0, (float)($_POST['price_yearly'] ?? 0)),
            'monthly_generation_tokens' => $tokens,
            'course_discount_percent' => min(100, max(0, (int)($_POST['course_discount_percent'] ?? 0))),
            'is_active'               => !empty($_POST['is_active']) ? 1 : 0,
        ];
        if ($planId > 0 && $data['name'] !== '') {
            $dbh->update('subscription_plans', $data, 'id = ?', [$planId]);
            $flash = ['type' => 'success', 'msg' => 'Тариф сохранён.'];
        } else {
            $flash = ['type' => 'error', 'msg' => 'Некорректные данные тарифа.'];
        }
    }
}

$plans = $dbh->query("SELECT * FROM subscription_plans ORDER BY sort_order ASC");
$csrf = generateCSRFToken();

include __DIR__ . '/../includes/header.php';
?>

<div class="page-header">
    <h1>Тарифы подписки</h1>
    <p><a href="/admin/subscriptions/">&larr; К списку подписок</a></p>
</div>

<?php if ($flash): ?>
    <div class="badge <?php echo $flash['type'] === 'success' ? 'badge-success' : 'badge-danger'; ?>" style="display:block;padding:12px 16px;margin-bottom:18px;">
        <?php echo htmlspecialchars($flash['msg']); ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">
<?php foreach ($plans as $plan): ?>
    <div class="content-card">
        <div class="card-body">
            <form method="post">
                <input type="hidden" name="csrf" value="<?php echo htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="plan_id" value="<?php echo (int)$plan['id']; ?>">

                <h3 style="margin-top:0;">
                    <?php echo htmlspecialchars($plan['name']); ?>
                    <span class="badge badge-info"><?php echo htmlspecialchars($plan['slug']); ?></span>
                </h3>

                <label style="display:block;margin:10px 0 4px;font-size:13px;">Название</label>
                <input type="text" name="name" value="<?php echo htmlspecialchars($plan['name'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;" required>

                <label style="display:block;margin:10px 0 4px;font-size:13px;">Цена в месяц, ₽</label>
                <input type="number" step="0.01" min="0" name="price_monthly" value="<?php echo htmlspecialchars($plan['price_monthly'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;">

                <label style="display:block;margin:10px 0 4px;font-size:13px;">Цена в год, ₽</label>
                <input type="number" step="0.01" min="0" name="price_yearly" value="<?php echo htmlspecialchars($plan['price_yearly'], ENT_QUOTES, 'UTF-8'); ?>" style="width:100%;padding:8px;">

                <label style="display:block;margin:10px 0 4px;font-size:13px;">Токенов ФОП в месяц <span style="color:#888;">(пусто = безлимит)</span></label>
                <input type="number" min="0" name="monthly_generation_tokens" value="<?php echo $plan['monthly_generation_tokens'] === null ? '' : (int)$plan['monthly_generation_tokens']; ?>" placeholder="безлимит" style="width:100%;padding:8px;">

                <label style="display:block;margin:10px 0 4px;font-size:13px;">Скидка на курсы, %</label>
                <input type="number" min="0" max="100" name="course_discount_percent" value="<?php echo (int)$plan['course_discount_percent']; ?>" style="width:100%;padding:8px;">

                <label style="display:flex;align-items:center;gap:8px;margin:14px 0;">
                    <input type="checkbox" name="is_active" value="1" <?php echo $plan['is_active'] ? 'checked' : ''; ?>>
                    Активен (показывать на лендинге)
                </label>

                <button type="submit" class="btn btn-primary btn-sm">Сохранить</button>
            </form>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

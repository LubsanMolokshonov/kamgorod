<?php
/**
 * Разовая рассылка держателям пожизненной скидки лояльности (users.has_lifetime_discount = 1):
 * напоминание о скидке (−25% корзина / −10% курсы, ставки эффективные — с учётом
 * individual_cart_discount) + персональная подборка вебинаров-видеолекций.
 *
 * Сегмент: has_lifetime_discount = 1, минус отписки. Скидка штатная (LoyaltyDiscount),
 * ничего выдавать не нужно — письмо только напоминает и ведёт по magic-ссылкам,
 * чтобы получатель пришёл авторизованным (иначе скидка в корзине не применится).
 *
 * Подбор вебинаров (до 3): статус videolecture/scheduled/live, сначала совпадения
 * по специализациям (user_specializations ↔ webinar_specializations), затем по типу
 * учреждения (institution_type_id ↔ webinar_audience_types), затем по популярности;
 * вебинары, на которые получатель уже регистрировался, уходят в конец очереди.
 *
 * Очередь — segment_promo_email_log (миграция 158), паттерн send_vospitateli_pp_invitation.php.
 *
 * Режимы:
 *   --populate              заполнить очередь
 *   --send                  отправить пачку pending
 *   --batch=N               писем за прогон (default 50)
 *   --daily-cap=N           стоп при N отправленных за день (default 500)
 *   --test=email@host       одна отправка на адрес (очередь не трогает)
 *   --dry-run               не отправлять, только показать
 *   --preview=/path.html    сохранить HTML письма в файл (вместе с --test)
 *   --retry                 вернуть failed (кроме invalid_email) в pending
 *   --pause / --resume      пауза для cron
 *   --status                статистика по логу
 *
 * Запуск (прод):
 *   docker exec pedagogy_web php /var/www/html/scripts/send_lifetime_webinar_reminder.php --populate
 *   docker exec pedagogy_web php /var/www/html/scripts/send_lifetime_webinar_reminder.php --send --batch=50 --daily-cap=500
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/classes/LoyaltyDiscount.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/includes/email-helper.php';

set_time_limit(0);

const CAMPAIGN_CODE = 'lifetime25_webinars_jul2026';
const UTM           = 'utm_source=email&utm_medium=promo&utm_campaign=lifetime25-webinars-jul2026';
const MAX_RECS      = 2; // 2 карточки + каталог = 3 magic-ссылки (держим плотность ссылок близко к эталону)

// === CLI args ===
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$batch       = (int)($args['batch'] ?? 50);
$dailyCap    = (int)($args['daily-cap'] ?? 500);
$dryRun      = !empty($args['dry-run']);
$testEmail   = is_string($args['test'] ?? null) ? $args['test'] : null;
$previewPath = is_string($args['preview'] ?? null) ? $args['preview'] : null;

$LOCK  = '/tmp/lifetime_webinar_reminder.lock';
$PAUSE = '/tmp/lifetime_webinar_reminder.pause';

if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

// Сегмент — переиспользуется в populate и status
$SEGMENT_WHERE = "
    u.email IS NOT NULL AND u.email <> ''
    AND u.has_lifetime_discount = 1
    AND u.email NOT IN (SELECT email FROM email_unsubscribes)
";

// === --status ===
if (!empty($args['status'])) {
    echo "Campaign " . CAMPAIGN_CODE . " — напоминание о loyalty-скидке + подборка вебинаров\n\n";
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM segment_promo_email_log WHERE campaign_code = ? GROUP BY status");
    $rows->execute([CAMPAIGN_CODE]);
    foreach ($rows as $r) printf("  %-8s %d\n", $r['status'], $r['c']);
    $t = $db->prepare("SELECT COUNT(*) FROM segment_promo_email_log WHERE campaign_code = ? AND status='sent' AND sent_at >= CURDATE()");
    $t->execute([CAMPAIGN_CODE]);
    echo "\nSent today: " . (int)$t->fetchColumn() . "\n";
    exit(0);
}

// === --test (одна отправка, очередь не трогаем) ===
if ($testEmail) {
    $u = $db->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
    $u->execute([$testEmail]);
    $row = $u->fetch();
    $userId = (int)($row['id'] ?? 0);
    $recs = pickRecommendations($db, $userId, $testEmail, MAX_RECS);
    if (!$recs) { fwrite(STDERR, "Нет вебинаров для рекомендации (пустой каталог?)\n"); exit(1); }
    sendOne($db, $testEmail, $row['full_name'] ?? '', $userId, $recs, $dryRun, $previewPath);
    echo "Test done -> {$testEmail}" . ($userId ? " (user #{$userId})" : " (нет в users — ссылки без авторизации)") . "\n";
    exit(0);
}

// === --retry: транзиентные сбои Unisender обратно в pending ===
if (!empty($args['retry'])) {
    $stmt = $db->prepare("
        UPDATE segment_promo_email_log SET status='pending', error=NULL, sent_at=NULL
        WHERE campaign_code = ? AND status='failed' AND (error IS NULL OR error NOT LIKE '%invalid%')
    ");
    $stmt->execute([CAMPAIGN_CODE]);
    echo "Requeued {$stmt->rowCount()} failed rows.\n";
    exit(0);
}

// === --populate ===
if (!empty($args['populate'])) {
    if ($dryRun) {
        $stmt = $db->query("SELECT COUNT(DISTINCT u.id) FROM users u WHERE {$SEGMENT_WHERE}");
        echo "[DRY] Segment size: " . (int)$stmt->fetchColumn() . "\n";
        exit(0);
    }
    echo "Populating segment_promo_email_log (" . CAMPAIGN_CODE . ")...\n";
    $stmt = $db->prepare("
        INSERT IGNORE INTO segment_promo_email_log (campaign_code, user_id, email, status)
        SELECT ?, u.id, u.email, 'pending'
        FROM users u
        WHERE {$SEGMENT_WHERE}
    ");
    $stmt->execute([CAMPAIGN_CODE]);
    echo "Inserted {$stmt->rowCount()} pending rows.\n";
    exit(0);
}

// === --send ===
if (empty($args['send'])) {
    fwrite(STDERR, "Specify one of: --populate | --send | --test=... | --status | --pause/--resume\n");
    exit(1);
}

if (file_exists($PAUSE)) { echo "Paused (flag at {$PAUSE}).\n"; exit(0); }

$lockFp = fopen($LOCK, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another run is in progress (lock).\n"; exit(0);
}
register_shutdown_function(function() use ($lockFp, $LOCK) {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    @unlink($LOCK);
});

// daily cap
$todayCount = $db->prepare("
    SELECT COUNT(*) FROM segment_promo_email_log
    WHERE campaign_code = ? AND status = 'sent' AND sent_at >= CURDATE()
");
$todayCount->execute([CAMPAIGN_CODE]);
$sentToday = (int)$todayCount->fetchColumn();
if ($sentToday >= $dailyCap) {
    echo "Daily cap reached ({$sentToday}/{$dailyCap}).\n"; exit(0);
}
$thisRun = min($batch, $dailyCap - $sentToday);

$pick = $db->prepare("
    SELECT l.id, l.user_id, l.email, u.full_name
    FROM segment_promo_email_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.campaign_code = ? AND l.status = 'pending'
    ORDER BY l.id
    LIMIT {$thisRun}
");
$pick->execute([CAMPAIGN_CODE]);
$rows = $pick->fetchAll();

if (!$rows) { echo "No pending rows.\n"; exit(0); }

echo "[" . date('Y-m-d H:i:s') . "] " . CAMPAIGN_CODE . ": sending up to " . count($rows) . " (today: {$sentToday}/{$dailyCap})\n";

$sent = $failed = $skipped = 0;
foreach ($rows as $r) {
    $email = $r['email'];
    $name  = $r['full_name'] ?: '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        markStatus($db, $r['id'], 'skipped', 'invalid_email');
        $skipped++;
        continue;
    }

    // отписка могла случиться после --populate — перепроверяем перед каждой отправкой
    $unsub = $db->prepare("SELECT 1 FROM email_unsubscribes WHERE email = ? LIMIT 1");
    $unsub->execute([$email]);
    if ($unsub->fetchColumn()) {
        markStatus($db, $r['id'], 'skipped', 'unsubscribed');
        $skipped++;
        continue;
    }

    // Жёсткий потолок кампании: не больше 1 письма получателю за 24 часа.
    // Глобальный throttle проекта (CHAIN_*) на проде отключён (=0 → no-op), а эта
    // рассылка идёт параллельно с кампанией воспитателей (пересечение ~97 чел.) —
    // здешний кап гарантирует, что никто не получит два письма в один день.
    // Кому письмо уже уходило за сутки — остаётся pending, уйдёт следующим прогоном.
    $recentCap = CHAIN_DAILY_CAP_PER_RECIPIENT > 0 ? CHAIN_DAILY_CAP_PER_RECIPIENT : 1;
    if (recipientRecentlyEmailed($db, $email, CHAIN_MIN_INTERVAL_MINUTES)
        || recipientReachedDailyCap($db, $email, $recentCap)) {
        // оставляем pending — подхватится следующим прогоном
        echo "[SKIP-THROTTLE] {$email}\n";
        continue;
    }

    // индивидуальная ставка могла быть обнулена — таким «скидочное» письмо не шлём
    $cartRate = LoyaltyDiscount::getEffectiveRates($db, (int)$r['user_id'])['cart'];
    if ($cartRate <= 0) {
        markStatus($db, $r['id'], 'skipped', 'zero_cart_rate');
        $skipped++;
        continue;
    }

    $recs = pickRecommendations($db, (int)$r['user_id'], $email, MAX_RECS);
    if (!$recs) {
        markStatus($db, $r['id'], 'skipped', 'no_recommendations');
        $skipped++;
        continue;
    }

    if ($dryRun) {
        $titles = implode(' | ', array_map(fn($w) => mb_substr($w['title'], 0, 40), $recs));
        echo "[DRY] {$email} — {$name} — rate " . (int)round($cartRate * 100) . "% — {$titles}\n";
        $sent++;
        continue;
    }

    try {
        $res = sendOne($db, $email, $name, (int)$r['user_id'], $recs, false, null);
        $upd = $db->prepare("UPDATE segment_promo_email_log SET status='sent', sent_at=NOW(), unisender_id=? WHERE id=?");
        $upd->execute([$res['unisender_id'] ?? null, $r['id']]);
        $sent++;
        echo "[SENT] {$email}\n";
    } catch (\Throwable $e) {
        markStatus($db, $r['id'], 'failed', substr($e->getMessage(), 0, 500));
        $failed++;
        echo "[FAIL] {$email} — " . $e->getMessage() . "\n";
    }

    usleep(1_500_000); // 1.5s между письмами
}

echo "Done. sent={$sent} failed={$failed} skipped={$skipped}\n";
exit(0);

// =============== helpers ===============

function markStatus(PDO $db, int $id, string $status, ?string $error = null): void {
    $stmt = $db->prepare("UPDATE segment_promo_email_log SET status=?, error=?, sent_at=NOW() WHERE id=?");
    $stmt->execute([$status, $error, $id]);
}

/**
 * Подбор до $limit вебинаров: специализации → тип учреждения → популярность.
 * Сначала вебинары, на которые получатель ещё не регистрировался; если их
 * меньше лимита — добираем из остальных (регистрация ≠ купленный сертификат).
 */
function pickRecommendations(PDO $db, int $userId, string $email, int $limit): array {
    $sql = "
        SELECT w.id, w.title, w.slug, w.certificate_price, w.certificate_hours, w.registrations_count,
            (SELECT COUNT(*) FROM webinar_specializations ws
               JOIN user_specializations us ON us.specialization_id = ws.specialization_id
              WHERE ws.webinar_id = w.id AND us.user_id = ?) AS spec_matches,
            EXISTS(SELECT 1 FROM webinar_audience_types wat
                     JOIN users u2 ON u2.institution_type_id = wat.audience_type_id
                    WHERE wat.webinar_id = w.id AND u2.id = ?) AS type_match,
            EXISTS(SELECT 1 FROM webinar_registrations wr
                    WHERE wr.webinar_id = w.id AND wr.email = ?) AS already_registered
        FROM webinars w
        WHERE w.status IN ('videolecture', 'scheduled', 'live')
        ORDER BY already_registered ASC, spec_matches DESC, type_match DESC, w.registrations_count DESC
        LIMIT " . (int)$limit;
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId, $userId, $email]);

    $recs = [];
    foreach ($stmt->fetchAll() as $w) {
        if ((int)$w['spec_matches'] > 0) {
            $badge = 'Под вашу специализацию';
            $badgeClass = 'badge-green';
        } elseif ((int)$w['type_match'] === 1) {
            $badge = 'Для вашего учреждения';
            $badgeClass = '';
        } else {
            $badge = 'Выбор коллег';
            $badgeClass = 'badge-orange';
        }
        $recs[] = [
            'id'    => (int)$w['id'],
            'title' => $w['title'],
            'slug'  => $w['slug'],
            'price' => (float)$w['certificate_price'],
            'hours' => (int)$w['certificate_hours'],
            'badge' => $badge,
            'badge_class' => $badgeClass,
        ];
    }
    return $recs;
}

function sendOne(PDO $db, string $email, string $name, int $userId, array $recs, bool $dryRun, ?string $previewPath): array {
    // эффективные ставки (individual_* перекрывают стандартные)
    $rates = $userId > 0
        ? LoyaltyDiscount::getEffectiveRates($db, $userId)
        : ['cart' => LoyaltyDiscount::RATE_CART, 'course' => LoyaltyDiscount::RATE_COURSE];
    $cartPercent   = (int)round($rates['cart'] * 100);
    $coursePercent = (int)round($rates['course'] * 100);

    // персональный sender (детерминированная ротация)
    $sender = CourseEmailChain::pickPersonalSender($email);
    $signatureName = explode(',', $sender['from_name'])[0];

    // unsubscribe-токен (формат pages/unsubscribe.php)
    $token = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
    $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $token;

    // magic-ссылки: получатель приходит авторизованным — иначе скидка в корзине не применится
    $webinars = [];
    foreach ($recs as $w) {
        $calc = LoyaltyDiscount::calculateCartDiscount($w['price'], $rates['cart']);
        $targetPath = '/vebinar/' . $w['slug'] . '?' . UTM;
        $webinars[] = $w + [
            'price_discounted' => $calc['final'],
            'url' => $userId > 0 ? generateMagicUrl($userId, $targetPath, 14) : SITE_URL . $targetPath,
        ];
    }
    $catalogPath = '/vebinary/videolektsii/?' . UTM;
    $catalog_url = $userId > 0 ? generateMagicUrl($userId, $catalogPath, 14) : SITE_URL . $catalogPath;

    // переменные шаблона
    $user_name               = $name;
    $discount_percent        = $cartPercent;
    $course_discount_percent = $coursePercent;
    $site_url                = SITE_URL;
    $sender_signature        = $signatureName . ', ФГОС-Практикум';
    $footer_reason           = 'вы совершали покупки на fgos.pro — за вашим аккаунтом закреплена постоянная скидка';
    $email_subject           = 'Подобрали вебинары для вас — скидка уже закреплена за аккаунтом';

    ob_start();
    include BASE_PATH . '/includes/email-templates/lifetime_webinar_reminder.php';
    $html = ob_get_clean();

    if ($previewPath) {
        file_put_contents($previewPath, $html);
        echo "Preview saved -> {$previewPath}\n";
    }

    $fmt = fn($v) => abs($v - round($v)) < 0.005 ? number_format($v, 0, ',', ' ') : number_format($v, 2, ',', ' ');

    $text  = "Здравствуйте" . ($name ? ', ' . $name : '') . ".\n\n";
    $text .= "Вы уже оплачивали участие на fgos.pro, поэтому за вашим аккаунтом закреплена постоянная скидка: {$cartPercent}% на вебинары, конкурсы, олимпиады и публикации и {$coursePercent}% на курсы. Она не сгорает, промокод не нужен — цена пересчитается автоматически при оплате из вашего аккаунта.\n\n";
    $text .= "Подобрали для вас вебинары (в записи, с именным сертификатом):\n\n";
    foreach ($webinars as $w) {
        $text .= "— {$w['title']}\n";
        $text .= "  Сертификат: " . $fmt($w['price_discounted']) . " руб. вместо " . $fmt($w['price']) . " руб.\n";
        $text .= "  {$w['url']}\n\n";
    }
    $text .= "Все вебинары: {$catalog_url}\n\n";
    $text .= "Ссылки откроют сайт сразу под вашим аккаунтом — скидка применится автоматически.\n\n";
    $text .= "— {$sender_signature}\n\n";
    $text .= "Если рассылка не нужна — отписаться: {$unsubscribe_url}\n";

    if ($dryRun) {
        echo "[DRY] -> {$email}\n  subject: {$email_subject}\n  from: {$sender['from_name']}\n";
        foreach ($webinars as $w) {
            echo "  [{$w['badge']}] {$w['title']} — " . $fmt($w['price']) . " -> " . $fmt($w['price_discounted']) . "\n";
        }
        return ['ok' => true, 'unisender_id' => null];
    }

    return EmailDispatcher::send([
        'to_email'        => $email,
        'to_name'         => $name,
        'subject'         => $email_subject,
        'html'            => $html,
        'text'            => $text,
        'from_name'       => $sender['from_name'],
        'reply_to'        => $sender['reply_to'],
        'reply_to_name'   => $sender['reply_to_name'],
        'unsubscribe_url' => $unsubscribe_url,
        'meta'            => [
            'email_type'      => 'webinar',
            'touchpoint_code' => CAMPAIGN_CODE,
            'recipient_email' => $email,
            'user_id'         => $userId,
        ],
    ]);
}

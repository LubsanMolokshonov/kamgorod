<?php
/**
 * Разовая промо-рассылка сегменту «воспитатели»: курс переподготовки
 * «Содержание и методика современного дошкольного образования в деятельности
 * воспитателя» (курс #64, 620 ч) с персональной скидкой 10%.
 *
 * Сегмент (union, минус отписки):
 *   - users.institution_type_id = 1 (тип аудитории «ДОУ»)
 *   - users.profession LIKE '%воспит%'
 *   - специализации «Воспитатель / Старший / Младший / ГПД» (user_specializations)
 * Исключаются: отписанные и те, у кого уже есть незакрытая заявка на этот курс.
 *
 * Скидка — штатный механизм email_campaign_discounts (EmailCampaignDiscount):
 * подхватывается автоматически в ajax/create-course-payment.php, промокод не нужен.
 * Выдаётся при --populate всему сегменту и обновляется при отправке письма.
 *
 * Очередь — segment_promo_email_log (миграция 158), паттерн send_poleznoe_leto_invitation.php.
 *
 * Режимы:
 *   --populate              заполнить очередь + выдать скидки всему сегменту
 *   --send                  отправить пачку pending
 *   --batch=N               писем за прогон (default 50)
 *   --daily-cap=N           стоп при N отправленных за день (default 500)
 *   --expires=DATETIME      дедлайн скидки (default 2026-07-21 23:59:59)
 *   --test=email@host       одна отправка на адрес (очередь не трогает; скидку выдаёт, только если адрес есть в users)
 *   --dry-run               не отправлять, только показать
 *   --retry                 вернуть failed (кроме invalid_email) в pending
 *   --pause / --resume      пауза для cron
 *   --status                статистика по логу и скидкам
 *
 * Запуск (прод):
 *   docker exec pedagogy_web php /var/www/html/scripts/send_vospitateli_pp_invitation.php --populate
 *   docker exec pedagogy_web php /var/www/html/scripts/send_vospitateli_pp_invitation.php --send --batch=50 --daily-cap=500
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/classes/EmailCampaignDiscount.php';
require_once BASE_PATH . '/classes/CoursePriceAB.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/includes/email-helper.php';

set_time_limit(0);

const CAMPAIGN_CODE   = 'vospitateli_pp10_jul2026';
const TARGET_COURSE_ID = 64;
const DISCOUNT_RATE   = 0.10;
const UTM             = 'utm_source=email&utm_medium=promo&utm_campaign=vospitateli-pp10-jul2026';

// === CLI args ===
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$batch     = (int)($args['batch'] ?? 50);
$dailyCap  = (int)($args['daily-cap'] ?? 500);
$dryRun    = !empty($args['dry-run']);
$testEmail = is_string($args['test'] ?? null) ? $args['test'] : null;
$expiresAt = is_string($args['expires'] ?? null) ? $args['expires'] : '2026-07-21 23:59:59';

if (strtotime($expiresAt) === false) { fwrite(STDERR, "Bad --expires\n"); exit(1); }

$LOCK  = '/tmp/vospitateli_pp_invitation.lock';
$PAUSE = '/tmp/vospitateli_pp_invitation.pause';

if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

// --- курс
$c = $db->prepare("SELECT id, title, slug, hours, price, program_type FROM courses WHERE id = ? AND is_active = 1");
$c->execute([TARGET_COURSE_ID]);
$course = $c->fetch();
if (!$course) { fwrite(STDERR, "Course #" . TARGET_COURSE_ID . " not found or inactive\n"); exit(1); }

// Витринная цена (фикс-скидка ПП уже учтена) и цена со скидкой кампании
$priceCurrent = CoursePriceAB::getAdjustedPrice((float)$course['price'], CoursePriceAB::getVariant(), $course['program_type']);
$calc = EmailCampaignDiscount::calculate($priceCurrent, DISCOUNT_RATE);
$priceDiscounted = $calc['final'];

// «21 июля» для текста письма
$monthsRu = [1=>'января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
$expTs = strtotime($expiresAt);
$deadlineLabel = (int)date('j', $expTs) . ' ' . $monthsRu[(int)date('n', $expTs)];

// Сегмент «воспитатели» — переиспользуется в populate и status
$SEGMENT_WHERE = "
    u.email IS NOT NULL AND u.email <> ''
    AND u.email NOT IN (SELECT email FROM email_unsubscribes)
    AND (
        u.institution_type_id = 1
        OR u.profession LIKE '%воспит%'
        OR u.id IN (
            SELECT us.user_id FROM user_specializations us
            JOIN audience_specializations s ON s.id = us.specialization_id
            WHERE s.name LIKE '%оспитат%'
        )
    )
    AND u.email NOT IN (
        SELECT ce.email FROM course_enrollments ce
        WHERE ce.course_id = " . TARGET_COURSE_ID . " AND ce.status <> 'cancelled'
    )
";

// === --status ===
if (!empty($args['status'])) {
    echo "Campaign " . CAMPAIGN_CODE . " — course #{$course['id']} «{$course['title']}»\n";
    echo "Price: {$priceCurrent} -> {$priceDiscounted} (-" . (int)(DISCOUNT_RATE * 100) . "%), deadline {$expiresAt}\n\n";
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM segment_promo_email_log WHERE campaign_code = ? GROUP BY status");
    $rows->execute([CAMPAIGN_CODE]);
    foreach ($rows as $r) printf("  %-8s %d\n", $r['status'], $r['c']);
    $d = $db->prepare("SELECT COUNT(*) total, SUM(used_in_order_id IS NOT NULL) used FROM email_campaign_discounts WHERE campaign_code = ?");
    $d->execute([CAMPAIGN_CODE]);
    $dr = $d->fetch();
    echo "\nDiscounts: {$dr['total']} granted, " . (int)$dr['used'] . " used\n";
    exit(0);
}

// === --test (одна отправка, очередь не трогаем) ===
if ($testEmail) {
    $u = $db->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
    $u->execute([$testEmail]);
    $row = $u->fetch();
    $userId = (int)($row['id'] ?? 0);
    if ($userId > 0 && !$dryRun) {
        EmailCampaignDiscount::upsert($db, CAMPAIGN_CODE, $userId, $testEmail, DISCOUNT_RATE, $expiresAt);
        echo "Discount granted to user #{$userId}.\n";
    }
    sendOne($testEmail, $row['full_name'] ?? 'Коллега', $userId, $course, $priceCurrent, $priceDiscounted, $deadlineLabel, $dryRun);
    echo "Test done -> {$testEmail}\n";
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

    // Скидка выдаётся сразу всему сегменту (upsert обновит rate/expires при повторном прогоне)
    echo "Granting " . (int)(DISCOUNT_RATE * 100) . "% discounts until {$expiresAt}...\n";
    $rows = $db->prepare("SELECT user_id, email FROM segment_promo_email_log WHERE campaign_code = ?");
    $rows->execute([CAMPAIGN_CODE]);
    $granted = 0;
    foreach ($rows as $r) {
        EmailCampaignDiscount::upsert($db, CAMPAIGN_CODE, (int)$r['user_id'], $r['email'], DISCOUNT_RATE, $expiresAt);
        $granted++;
    }
    echo "Discounts granted/refreshed: {$granted}.\n";
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

    // общий throttle проекта: не слать, если недавно было другое письмо / достигнут дневной потолок получателя
    if (recipientRecentlyEmailed($db, $email, CHAIN_MIN_INTERVAL_MINUTES)
        || recipientReachedDailyCap($db, $email, CHAIN_DAILY_CAP_PER_RECIPIENT)) {
        // оставляем pending — подхватится следующим прогоном
        echo "[SKIP-THROTTLE] {$email}\n";
        continue;
    }

    if ($dryRun) {
        echo "[DRY] {$email} — {$name}\n";
        $sent++;
        continue;
    }

    try {
        // скидка гарантированно активна на момент получения письма
        EmailCampaignDiscount::upsert($db, CAMPAIGN_CODE, (int)$r['user_id'], $email, DISCOUNT_RATE, $expiresAt);

        $res = sendOne($email, $name, (int)$r['user_id'], $course, $priceCurrent, $priceDiscounted, $deadlineLabel, false);
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

function sendOne(string $email, string $name, int $userId, array $course, float $priceCurrent, float $priceDiscounted, string $deadlineLabel, bool $dryRun): array {
    // персональный sender (детерминированная ротация)
    $sender = CourseEmailChain::pickPersonalSender($email);
    $signatureName = explode(',', $sender['from_name'])[0];

    // unsubscribe-токен (формат pages/unsubscribe.php)
    $token = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
    $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $token;

    // magic-link: получатель приходит авторизованным сразу на страницу курса
    $targetPath = '/kursy/' . $course['slug'] . '/?' . UTM;
    $course_url = $userId > 0 ? generateMagicUrl($userId, $targetPath, 14) : SITE_URL . $targetPath;

    // переменные шаблона
    $user_name               = $name;
    $course_title            = $course['title'];
    $course_hours            = $course['hours'];
    $price_current           = $priceCurrent;
    $price_discounted        = $priceDiscounted;
    $discount_percent        = (int)(DISCOUNT_RATE * 100);
    $discount_deadline_label = $deadlineLabel;
    $site_url                = SITE_URL;
    $sender_signature        = $signatureName . ', ФГОС-Практикум';
    $footer_reason           = 'вы зарегистрированы на fgos.pro и указали работу в дошкольном образовании';
    $email_subject           = 'Переподготовка для воспитателя: ' . (int)$course['hours'] . ' часов, диплом установленного образца';

    ob_start();
    include BASE_PATH . '/includes/email-templates/vospitateli_pp_promo.php';
    $html = ob_get_clean();

    $fmtCur = number_format($priceCurrent, 0, ',', ' ');
    $fmtNew = number_format($priceDiscounted, 0, ',', ' ');

    $text  = "Здравствуйте" . ($name ? ', ' . $name : '') . ".\n\n";
    $text .= "Вы указали при регистрации, что работаете в дошкольном образовании. По профстандарту «Педагог» воспитателю ДОУ нужна профильная дошкольная подготовка — её подтверждает диплом о переподготовке.\n\n";
    $text .= "Программа: «{$course['title']}», " . (int)$course['hours'] . " часов, заочно с применением дистанционных технологий. Диплом вносится в ФИС ФРДО.\n\n";
    $text .= "До {$deadlineLabel} включительно для вас действует персональная скидка " . (int)(DISCOUNT_RATE * 100) . "%: {$fmtNew} руб. вместо {$fmtCur} руб. Скидка закреплена за вашим личным кабинетом, промокод не нужен — цена пересчитается при оплате.\n\n";
    $text .= "Программа курса: {$course_url}\n\n";
    $text .= "Есть вопросы или нужна рассрочка — просто ответьте на это письмо.\n\n";
    $text .= "— {$sender_signature}\n\n";
    $text .= "Если рассылка не нужна — отписаться: {$unsubscribe_url}\n";

    if ($dryRun) {
        echo "[DRY] -> {$email}\n  subject: {$email_subject}\n  from: {$sender['from_name']}\n  price: {$fmtCur} -> {$fmtNew}\n";
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
            'email_type'      => 'course',
            'touchpoint_code' => CAMPAIGN_CODE,
            'recipient_email' => $email,
            'user_id'         => $userId,
        ],
    ]);
}

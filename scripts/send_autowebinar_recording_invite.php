<?php
/**
 * Разовая массовая рассылка по записи автовебинара «Полезное лето. Особый ребёнок»
 * через Unisender Go (EmailDispatcher). Аудитория — вся реальная база (таблица users),
 * без старой базы (old_base_*). Письмо содержит материалы вебинара и magic-link на
 * pages/autowebinar-claim.php: переход = авто-логин + авто-регистрация на видеолекцию
 * + авто-зачёт теста → сразу оформление именного диплома (200 ₽).
 *
 * Отдельный лог autowebinar_recording_invite_log (webinar_invitation_log уже занят
 * июньским invite-кампейном этого же вебинара).
 *
 * Режимы:
 *   --slug=...           slug вебинара (default poleznoe-leto-osobyj-rebenok)
 *   --populate           заполнить лог строками pending для всех users (минус отписки)
 *   --send               отправить пачку (читает pending из лога)
 *   --batch=N            сколько отправить за прогон (default 100)
 *   --daily-cap=N        стоп при N отправленных за календарный день (default 2500)
 *   --test=email@host    одна отправка указанному адресу (без записи в лог)
 *   --dry-run            не отправлять, только показать кандидатов
 *   --pause / --resume   управление паузой (флаг-файл в /tmp/)
 *   --status             краткая статистика по логу
 *
 * Запуск (прод):
 *   docker exec pedagogy_web php /var/www/html/scripts/send_autowebinar_recording_invite.php --populate
 *   docker exec pedagogy_web php /var/www/html/scripts/send_autowebinar_recording_invite.php --send --batch=100 --daily-cap=2500
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';
require_once BASE_PATH . '/includes/email-helper.php';

set_time_limit(0);

// === материалы вебинара (ссылки от организатора) ===
const PRESENTATION_URL = 'https://clck.ru/3Txvx4'; // презентация эксперта
const FEEDBACK_URL     = 'https://clck.ru/3TcR6n'; // анкета обратной связи (подарок)
const RECORDING_URL    = 'https://clck.ru/3TxwFa'; // запись вебинара

// === CLI args ===
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$slug      = $args['slug']      ?? 'poleznoe-leto-osobyj-rebenok';
$batch     = (int)($args['batch']     ?? 100);
$dailyCap  = (int)($args['daily-cap'] ?? 2500);
$dryRun    = !empty($args['dry-run']);
$testEmail = $args['test']      ?? null;

$LOCK  = '/tmp/autowebinar_recording_invite.lock';
$PAUSE = '/tmp/autowebinar_recording_invite.pause';

// --- pause/resume
if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

// --- webinar
$w = $db->prepare("
    SELECT w.*, s.full_name AS speaker_name, s.position AS speaker_position, s.photo AS speaker_photo
    FROM webinars w
    LEFT JOIN speakers s ON w.speaker_id = s.id
    WHERE w.slug = ?
");
$w->execute([$slug]);
$webinar = $w->fetch();
if (!$webinar) { fwrite(STDERR, "Webinar not found: {$slug}\n"); exit(1); }
$webinarId = (int)$webinar['id'];

// === --status ===
if (!empty($args['status'])) {
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM autowebinar_recording_invite_log WHERE webinar_id=? GROUP BY status");
    $rows->execute([$webinarId]);
    echo "Webinar #{$webinarId} ({$webinar['title']})\n";
    foreach ($rows as $r) printf("  %-8s %d\n", $r['status'], $r['c']);
    exit(0);
}

// === --test (одна отправка, без БД) ===
if ($testEmail) {
    $u = $db->prepare("SELECT id, full_name FROM users WHERE email=? LIMIT 1");
    $u->execute([$testEmail]);
    $row = $u->fetch();
    sendOne($testEmail, $row['full_name'] ?? 'Тест', (int)($row['id'] ?? 0), $webinar, $dryRun);
    exit(0);
}

// === --populate ===
if (!empty($args['populate'])) {
    echo "Populating autowebinar_recording_invite_log for webinar #{$webinarId}...\n";
    // Вся база users, минус отписавшиеся. Реальные участники (52) включаются —
    // им письмо с материалами и записью уместно.
    $sql = "
        INSERT IGNORE INTO autowebinar_recording_invite_log (webinar_id, user_id, email, status)
        SELECT ?, u.id, u.email, 'pending'
        FROM users u
        WHERE u.email IS NOT NULL AND u.email <> ''
          AND u.email NOT IN (SELECT email FROM email_unsubscribes)
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$webinarId]);
    echo "Inserted {$stmt->rowCount()} pending rows.\n";
    exit(0);
}

// === --send ===
if (empty($args['send'])) {
    fwrite(STDERR, "Specify one of: --populate | --send | --test=... | --dry-run | --status | --pause/--resume\n");
    exit(1);
}

// pause-флаг
if (file_exists($PAUSE)) { echo "Paused (flag at {$PAUSE}).\n"; exit(0); }

// lock
$lockFp = fopen($LOCK, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another run is in progress (lock).\n"; exit(0);
}
register_shutdown_function(function() use ($lockFp, $LOCK) {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    @unlink($LOCK);
});

// daily cap check
$todayCount = $db->prepare("
    SELECT COUNT(*) FROM autowebinar_recording_invite_log
    WHERE webinar_id=? AND status='sent' AND sent_at >= CURDATE()
");
$todayCount->execute([$webinarId]);
$sentToday = (int)$todayCount->fetchColumn();
if ($sentToday >= $dailyCap) {
    echo "Daily cap reached ({$sentToday}/{$dailyCap}).\n"; exit(0);
}
$remainingToday = $dailyCap - $sentToday;
$thisRun = min($batch, $remainingToday);

// pull pending rows
$pick = $db->prepare("
    SELECT l.id, l.user_id, l.email, u.full_name
    FROM autowebinar_recording_invite_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.webinar_id = ? AND l.status = 'pending'
    ORDER BY l.id
    LIMIT {$thisRun}
");
$pick->execute([$webinarId]);
$rows = $pick->fetchAll();

if (!$rows) { echo "No pending rows.\n"; exit(0); }

echo "[" . date('Y-m-d H:i:s') . "] Webinar #{$webinarId}: sending up to " . count($rows) . " (today: {$sentToday}/{$dailyCap})\n";

$sent = $failed = $skipped = 0;
foreach ($rows as $r) {
    $email = $r['email'];
    $name  = $r['full_name'] ?: '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        markStatus($db, $r['id'], 'skipped', 'invalid_email');
        $skipped++;
        continue;
    }

    // Не дублируем письмо тем, кому в последние 4 часа уже слала другая цепочка
    // (снижает риск сдвоенных писем и спам-сигналов). Оставляем pending — повторится позже.
    if (defined('CHAIN_MIN_INTERVAL_MINUTES') && recipientRecentlyEmailed($db, $email, CHAIN_MIN_INTERVAL_MINUTES)) {
        echo "[WAIT] {$email} — recently emailed, leaving pending\n";
        $skipped++;
        continue;
    }

    if ($dryRun) {
        echo "[DRY] {$email} — {$name}\n";
        $sent++;
        continue;
    }

    try {
        $res = sendOne($email, $name, (int)$r['user_id'], $webinar, false);
        $upd = $db->prepare("UPDATE autowebinar_recording_invite_log SET status='sent', sent_at=NOW(), unisender_id=? WHERE id=?");
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
    $stmt = $db->prepare("UPDATE autowebinar_recording_invite_log SET status=?, error=?, sent_at=NOW() WHERE id=?");
    $stmt->execute([$status, $error, $id]);
}

function sendOne(string $email, string $name, int $userId, array $webinar, bool $dryRun): array {
    // персональный sender (детерминированная ротация Родион/Анна)
    $sender = CourseEmailChain::pickPersonalSender($email);
    $signatureName = explode(',', $sender['from_name'])[0];

    // unsubscribe-токен (формат pages/unsubscribe.php: base64(email:md5(email+SITE_URL)[0:16]))
    $token = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
    $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $token;

    // переменные шаблона
    $user_name         = $name;
    $webinar_title     = $webinar['title'];
    $speaker_name      = $webinar['speaker_name'] ?? '';
    $speaker_position  = $webinar['speaker_position'] ?? '';
    $speaker_photo     = '';
    if (!empty($webinar['speaker_photo'])) {
        $speaker_photo = str_starts_with($webinar['speaker_photo'], '/')
            ? SITE_URL . $webinar['speaker_photo']
            : SITE_URL . '/uploads/speakers/' . $webinar['speaker_photo'];
    }
    $certificate_hours = $webinar['certificate_hours'] ?? 2;
    $certificate_price = $webinar['certificate_price'] ?? 200;
    $site_url          = SITE_URL;
    $sender_signature  = $signatureName . ', ФГОС-Практикум';

    $presentation_url  = PRESENTATION_URL;
    $feedback_url      = FEEDBACK_URL;
    $recording_url     = RECORDING_URL;

    // magic-link на claim-страницу: авто-логин + авто-регистрация + авто-зачёт → диплом
    $utm = 'utm_source=email&utm_medium=recording_invite&utm_campaign=autowebinar-poleznoe-leto';
    $targetPath = '/pages/autowebinar-claim.php?w=' . (int)$webinar['id'] . '&' . $utm;
    if ($userId > 0) {
        $claim_link = generateMagicUrl($userId, $targetPath, 30);
    } else {
        $claim_link = SITE_URL . $targetPath;
    }

    ob_start();
    include BASE_PATH . '/includes/email-templates/autowebinar_recording_invite.php';
    $html = ob_get_clean();

    $subject = 'Запись вебинара и материалы — «' . $webinar_title . '»';

    $text  = "Здравствуйте" . ($name ? ', ' . $name : '') . ".\n\n";
    $text .= "Материалы вебинара «{$webinar_title}» готовы — можно посмотреть запись и оформить именной диплом участника.\n\n";
    $text .= "Запись вебинара: " . RECORDING_URL . "\n";
    $text .= "Презентация эксперта: " . PRESENTATION_URL . "\n";
    $text .= "Анкета обратной связи (за заполнение подарок): " . FEEDBACK_URL . "\n\n";
    $text .= "Оформить диплом (вход и регистрация автоматически): {$claim_link}\n\n";
    $text .= "Диплом на {$certificate_hours} ак. часа — {$certificate_price} ₽, подходит для портфолио и аттестации.\n\n";
    $text .= "— {$sender_signature}\n\n";
    $text .= "Если рассылка не нужна — отписаться: {$unsubscribe_url}\n";

    if ($dryRun) {
        echo "[DRY] -> {$email}\n  subject: {$subject}\n  from: {$sender['from_name']}\n  claim: {$claim_link}\n";
        return ['ok' => true, 'unisender_id' => null];
    }

    return EmailDispatcher::send([
        'to_email'        => $email,
        'to_name'         => $name,
        'subject'         => $subject,
        'html'            => $html,
        'text'            => $text,
        'from_name'       => $sender['from_name'],
        'reply_to'        => $sender['reply_to'],
        'reply_to_name'   => $sender['reply_to_name'],
        'unsubscribe_url' => $unsubscribe_url,
        'meta'            => [
            'email_type'      => 'autowebinar',
            'touchpoint_code' => 'recording_invite_mass',
            'recipient_email' => $email,
        ],
    ]);
}

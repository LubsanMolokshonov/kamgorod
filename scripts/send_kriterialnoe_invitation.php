<?php
/**
 * Массовая рассылка-приглашение на вебинар «Критериальное оценивание» через
 * Unisender Go (EmailDispatcher). Аудитория — учителя школ + пользователи без
 * заполненного типа учреждения.
 *
 * Режимы:
 *   --slug=...              slug вебинара (обяз.)
 *   --populate              заполнить webinar_invitation_log строками pending
 *   --send                  отправить пачку (читает pending из webinar_invitation_log)
 *   --batch=N               сколько отправить за один прогон (default 100)
 *   --daily-cap=N           стоп при N отправленных за календарный день (default 1600)
 *   --test=email@host       одна отправка указанному адресу (без БД)
 *   --dry-run               не отправлять, только показать кандидатов
 *   --pause                 создать /tmp/kriterialnoe_invitation.pause (cron остановится)
 *   --resume                удалить файл паузы
 *   --status                краткая статистика по логу
 *
 * Запуск (прод):
 *   docker exec pedagogy_web php scripts/send_kriterialnoe_invitation.php \
 *     --slug=kriterialnoe-ocenivanie-7-instrumentov --send --batch=1600 --daily-cap=1600
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

set_time_limit(0);

// === CLI args ===
$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$slug      = $args['slug']      ?? null;
$batch     = (int)($args['batch']     ?? 100);
$dailyCap  = (int)($args['daily-cap'] ?? 1600);
$dryRun    = !empty($args['dry-run']);
$testEmail = $args['test']      ?? null;

$LOCK  = '/tmp/kriterialnoe_invitation.lock';
$PAUSE = '/tmp/kriterialnoe_invitation.pause';

// --- pause/resume
if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

if (!$slug) { fwrite(STDERR, "ERROR: --slug required\n"); exit(1); }

// --- webinar
$w = $db->prepare("
    SELECT w.*, s.full_name AS speaker_name, s.position AS speaker_position
    FROM webinars w
    LEFT JOIN speakers s ON w.speaker_id = s.id
    WHERE w.slug = ?
");
$w->execute([$slug]);
$webinar = $w->fetch();
if (!$webinar) { fwrite(STDERR, "Webinar not found: {$slug}\n"); exit(1); }
$webinarId = (int)$webinar['id'];

// --- formatted date
$dt = new DateTime($webinar['scheduled_at'], new DateTimeZone('Europe/Moscow'));
$months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
$days   = ['воскресенье','понедельник','вторник','среда','четверг','пятница','суббота'];
$webinar_date          = $dt->format('j') . ' ' . $months[(int)$dt->format('n')-1];
$webinar_datetime_full = $webinar_date . ', ' . $days[(int)$dt->format('w')] . ', ' . $dt->format('H:i') . ' МСК';

// === --status ===
if (!empty($args['status'])) {
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM webinar_invitation_log WHERE webinar_id=? GROUP BY status");
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
    sendOne($testEmail, $row['full_name'] ?? 'Тест', (int)($row['id'] ?? 0), $webinar, $webinar_date, $webinar_datetime_full, $dryRun);
    exit(0);
}

// === --populate ===
if (!empty($args['populate'])) {
    echo "Populating webinar_invitation_log for webinar #{$webinarId}...\n";
    // Аудитория: учителя школ (institution_type 2,3) + пользователи без типа учреждения.
    // Фильтруем отписавшихся и уже зарегистрированных на этот вебинар.
    $sql = "
        INSERT IGNORE INTO webinar_invitation_log (webinar_id, user_id, email, status)
        SELECT ?, u.id, u.email, 'pending'
        FROM users u
        WHERE u.email IS NOT NULL AND u.email <> ''
          AND (u.institution_type_id IN (2,3) OR u.institution_type_id IS NULL)
          AND u.email NOT IN (SELECT email FROM email_unsubscribes)
          AND u.email NOT IN (
              SELECT wr.email FROM webinar_registrations wr
              WHERE wr.webinar_id = ? AND wr.status = 'registered'
          )
    ";
    $stmt = $db->prepare($sql);
    $stmt->execute([$webinarId, $webinarId]);
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
    SELECT COUNT(*) FROM webinar_invitation_log
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
    FROM webinar_invitation_log l
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

    if ($dryRun) {
        echo "[DRY] {$email} — {$name}\n";
        $sent++;
        continue;
    }

    try {
        $res = sendOne($email, $name, (int)$r['user_id'], $webinar, $webinar_date, $webinar_datetime_full, false);
        $upd = $db->prepare("UPDATE webinar_invitation_log SET status='sent', sent_at=NOW(), unisender_id=? WHERE id=?");
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
    $stmt = $db->prepare("UPDATE webinar_invitation_log SET status=?, error=?, sent_at=NOW() WHERE id=?");
    $stmt->execute([$status, $error, $id]);
}

function sendOne(string $email, string $name, int $userId, array $webinar, string $webinar_date, string $webinar_datetime_full, bool $dryRun): array {
    // персональный sender (детерминированная ротация)
    $sender = CourseEmailChain::pickPersonalSender($email);
    $signatureName = explode(',', $sender['from_name'])[0]; // «Анна» / «Родион»

    // unsubscribe-токен (тот же формат, что использует pages/unsubscribe.php: base64(email:md5(email+SITE_URL)[0:16]))
    $token = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
    $unsubscribe_url = SITE_URL . '/pages/unsubscribe.php?token=' . $token;

    // переменные шаблона
    $user_name             = $name;
    $webinar_title         = $webinar['title'];
    $webinar_slug          = $webinar['slug'];
    $webinar_description   = $webinar['short_description'] ?? '';
    $webinar_duration      = $webinar['duration_minutes'] ?? 90;
    $speaker_name          = $webinar['speaker_name'] ?? '';
    $speaker_position      = $webinar['speaker_position'] ?? '';
    $speaker_photo         = '';
    if (!empty($webinar['speaker_photo'])) {
        $speaker_photo = str_starts_with($webinar['speaker_photo'], '/')
            ? SITE_URL . $webinar['speaker_photo']
            : SITE_URL . '/uploads/speakers/' . $webinar['speaker_photo'];
    }
    $certificate_hours     = $webinar['certificate_hours'] ?? 2;
    $certificate_price     = $webinar['certificate_price'] ?? 200;
    $site_url              = SITE_URL;
    $sender_signature      = $signatureName . ', ФГОС-Практикум';
    $footer_reason         = 'вы зарегистрированы на fgos.pro';

    // magic-link: пользователь приходит уже авторизованным и сразу попадает на страницу вебинара
    $utm = 'utm_source=email&utm_medium=invite&utm_campaign=webinar-kriterialnoe-may2026';
    $targetPath = '/vebinar/' . $webinar['slug'] . '/?' . $utm;
    if ($userId > 0) {
        $webinar_link = generateMagicUrl($userId, $targetPath, 14);
    } else {
        $webinar_link = SITE_URL . $targetPath;
    }
    $webinarLink = $webinar_link;

    ob_start();
    include BASE_PATH . '/includes/email-templates/webinar_invitation_kriterialnoe.php';
    $html = ob_get_clean();

    // нейтральная тема: без «бесплатно», «приглашаем», «акция», эмодзи и !!!.
    $subject = 'Критериальное оценивание: вебинар ' . $webinar_date . ' для учителей';

    $text  = "Здравствуйте" . ($name ? ', ' . $name : '') . ".\n\n";
    $text .= "{$webinar_datetime_full} у нас вебинар «{$webinar_title}». Для участия нужна регистрация — это занимает минуту.\n\n";
    $text .= "Разберём, как сделать отметки прозрачными для учеников и родителей: 7 готовых инструментов — рубрикаторы, чек-листы, речевые скрипты, приёмы самооценки. 85% времени — практика по ФГОС.\n\n";
    if ($speaker_name) $text .= "Ведёт {$speaker_name}" . ($speaker_position ? ", {$speaker_position}" : '') . ". Длительность около {$webinar_duration} минут.\n\n";
    $text .= "Записаться: {$webinar_link}\n\n";
    $text .= "Не сможете быть в эфире — всё равно регистрируйтесь, пришлём ссылку на запись.\n\n";
    $text .= "После эфира можно оформить именной сертификат на {$certificate_hours} ч. — он не обязателен, но иногда нужен для портфолио.\n\n";
    $text .= "— {$sender_signature}\n\n";
    $text .= "Если рассылка не нужна — отписаться: {$unsubscribe_url}\n";

    if ($dryRun) {
        echo "[DRY] -> {$email}\n  subject: {$subject}\n  from: {$sender['from_name']}\n";
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
            'email_type'      => 'webinar',
            'touchpoint_code' => 'invitation_mass',
            'recipient_email' => $email,
        ],
    ]);
}

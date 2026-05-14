<?php
/**
 * Массовая POST-webinar рассылка: запись + презентация + анкета + предложение купить сертификат.
 * Для тех, кто НЕ был зарегистрирован на вебинар. Через Unisender Go (EmailDispatcher).
 *
 * Архитектурно — клон send_webinar_invitation.php, но:
 *   - своя таблица webinar_recording_invitation_log (миграция 111),
 *   - другой шаблон (webinar_recording_osobyj_rebenok.php),
 *   - magic-link ведёт на /pages/webinar-cert-quick.php?webinar_id=...
 *     (тихая регистрация + редирект на оплату сертификата),
 *   - туда же исключаем уже-зарегистрированных (им письмо ушло в день после вебинара).
 *
 * Режимы:
 *   --slug=osobyj-rebenok-10-shagov   slug вебинара (обяз.)
 *   --populate                        заполнить webinar_recording_invitation_log (idempotent)
 *   --send                            отправить пачку
 *   --batch=N                         сколько за прогон (default 50)
 *   --daily-cap=N                     дневная квота (default 3000)
 *   --test=email@host                 одна отправка указанному адресу (без БД)
 *   --dry-run                         не отправлять, только показать
 *   --pause / --resume                флаг паузы /tmp/webinar_recording_invitation.pause
 *   --status                          краткая статистика
 *
 * Cron (прод, окно 10–20 МСК, ~22 ч/сутки активной отправки):
 *   *\/5 10-20 * * * docker exec pedagogy_web php /var/www/html/scripts/send_webinar_recording_invitation.php \
 *     --slug=osobyj-rebenok-10-shagov --send --batch=50 --daily-cap=3000 \
 *     >> /var/log/webinar-recording-invitation.log 2>&1
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

set_time_limit(0);

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$slug      = $args['slug']      ?? null;
$batch     = (int)($args['batch']     ?? 50);
$dailyCap  = (int)($args['daily-cap'] ?? 3000);
$dryRun    = !empty($args['dry-run']);
$testEmail = $args['test']      ?? null;

$LOCK  = '/tmp/webinar_recording_invitation.lock';
$PAUSE = '/tmp/webinar_recording_invitation.pause';

if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

if (!$slug) { fwrite(STDERR, "ERROR: --slug required\n"); exit(1); }

$wStmt = $db->prepare("SELECT * FROM webinars WHERE slug = ? LIMIT 1");
$wStmt->execute([$slug]);
$webinar = $wStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) { fwrite(STDERR, "Webinar not found: {$slug}\n"); exit(1); }
$webinarId = (int)$webinar['id'];

// === Параметры рассылки (привязаны к slug osobyj-rebenok-10-shagov) ===
$RECORDING_URL    = 'https://clck.ru/3TdXMc';
$PRESENTATION_URL = 'https://clck.ru/3TdXRD';
$FEEDBACK_URL     = 'https://clck.ru/3TcR6n';
$EMAIL_SUBJECT    = 'Бесплатная запись вебинара по работе с особыми детьми + сертификат на 2 ак.часа';
$UTM_CAMPAIGN     = 'recording_mass_osobyj_rebenok';
$UTM_STRING       = 'utm_source=email&utm_medium=recording&utm_campaign=' . $UTM_CAMPAIGN;

// === --status ===
if (!empty($args['status'])) {
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM webinar_recording_invitation_log WHERE webinar_id=? GROUP BY status");
    $rows->execute([$webinarId]);
    echo "Webinar #{$webinarId} ({$webinar['title']})\n";
    foreach ($rows as $r) printf("  %-8s %d\n", $r['status'], $r['c']);
    exit(0);
}

// === --test (одна отправка, без БД) ===
if ($testEmail) {
    $u = $db->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
    $u->execute([$testEmail]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        fwrite(STDERR, "User with email {$testEmail} not found\n");
        exit(1);
    }
    $res = sendOne($testEmail, $row['full_name'] ?? 'Тест', (int)$row['id'], $webinar,
        $RECORDING_URL, $PRESENTATION_URL, $FEEDBACK_URL, $EMAIL_SUBJECT, $UTM_STRING, $UTM_CAMPAIGN, $dryRun);
    echo "[TEST SENT] {$testEmail} unisender_id=" . ($res['unisender_id'] ?? '') . "\n";
    exit(0);
}

// === --populate ===
if (!empty($args['populate'])) {
    echo "Populating webinar_recording_invitation_log for webinar #{$webinarId}...\n";
    // Кандидаты: воспитательская аудитория (ДОУ + доп. образование + «дошкольники»),
    // исключая отписавшихся и уже-зарегистрированных на этот вебинар.
    $sql = "
        INSERT IGNORE INTO webinar_recording_invitation_log (webinar_id, user_id, email, status)
        SELECT ?, u.id, u.email, 'pending'
        FROM users u
        WHERE u.email IS NOT NULL AND u.email <> ''
          AND u.institution_type_id IN (1, 5, 10)
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

if (file_exists($PAUSE)) { echo "Paused (flag at {$PAUSE}).\n"; exit(0); }

$lockFp = fopen($LOCK, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another run is in progress (lock).\n"; exit(0);
}
register_shutdown_function(function() use ($lockFp, $LOCK) {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    @unlink($LOCK);
});

// Дневная квота
$todayCount = $db->prepare("
    SELECT COUNT(*) FROM webinar_recording_invitation_log
    WHERE webinar_id = ? AND status = 'sent' AND sent_at >= CURDATE()
");
$todayCount->execute([$webinarId]);
$sentToday = (int)$todayCount->fetchColumn();
if ($sentToday >= $dailyCap) {
    echo "Daily cap reached ({$sentToday}/{$dailyCap}).\n"; exit(0);
}
$thisRun = min($batch, $dailyCap - $sentToday);

$pick = $db->prepare("
    SELECT l.id, l.user_id, l.email, u.full_name
    FROM webinar_recording_invitation_log l
    JOIN users u ON u.id = l.user_id
    WHERE l.webinar_id = ? AND l.status = 'pending'
    ORDER BY l.id
    LIMIT {$thisRun}
");
$pick->execute([$webinarId]);
$rows = $pick->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "No pending rows.\n"; exit(0); }

echo "[" . date('Y-m-d H:i:s') . "] Webinar #{$webinarId} (recording): sending up to " . count($rows) . " (today: {$sentToday}/{$dailyCap})\n";

$sent = $failed = $skipped = 0;
foreach ($rows as $r) {
    $email = $r['email'];
    $name  = $r['full_name'] ?: '';
    $userId = (int)$r['user_id'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        markStatus($db, (int)$r['id'], 'skipped', 'invalid_email');
        $skipped++;
        continue;
    }

    // re-check: вдруг отписался / зарегистрировался за время в очереди
    $check = $db->prepare("
        SELECT
          (SELECT 1 FROM email_unsubscribes WHERE email = ? LIMIT 1) AS unsub,
          (SELECT 1 FROM webinar_registrations WHERE webinar_id = ?
              AND (user_id = ? OR LOWER(email) = LOWER(?))
              AND status = 'registered' LIMIT 1) AS reg
    ");
    $check->execute([$email, $webinarId, $userId, $email]);
    $chk = $check->fetch(PDO::FETCH_ASSOC);
    if (!empty($chk['unsub'])) {
        markStatus($db, (int)$r['id'], 'skipped', 'unsubscribed');
        $skipped++;
        echo "[SKIP] {$email} — отписан\n";
        continue;
    }
    if (!empty($chk['reg'])) {
        markStatus($db, (int)$r['id'], 'skipped', 'already_registered');
        $skipped++;
        echo "[SKIP] {$email} — уже зарегистрирован\n";
        continue;
    }

    if ($dryRun) {
        echo "[DRY] {$email} — {$name}\n";
        $sent++;
        continue;
    }

    try {
        $res = sendOne($email, $name, $userId, $webinar,
            $RECORDING_URL, $PRESENTATION_URL, $FEEDBACK_URL, $EMAIL_SUBJECT, $UTM_STRING, $UTM_CAMPAIGN, false);
        $upd = $db->prepare("UPDATE webinar_recording_invitation_log SET status='sent', sent_at=NOW(), unisender_id=? WHERE id=?");
        $upd->execute([$res['unisender_id'] ?? null, $r['id']]);
        $sent++;
        echo "[SENT] {$email}\n";
    } catch (\Throwable $e) {
        markStatus($db, (int)$r['id'], 'failed', substr($e->getMessage(), 0, 500));
        $failed++;
        echo "[FAIL] {$email} — " . $e->getMessage() . "\n";
    }

    usleep(300000); // 0.3s между письмами
}

echo "Done. sent={$sent} failed={$failed} skipped={$skipped}\n";
exit(0);

// =============== helpers ===============

function markStatus(PDO $db, int $id, string $status, ?string $error = null): void {
    $stmt = $db->prepare("UPDATE webinar_recording_invitation_log SET status=?, error=?, sent_at=NOW() WHERE id=?");
    $stmt->execute([$status, $error, $id]);
}

function sendOne(
    string $email, string $name, int $userId, array $webinar,
    string $recordingUrl, string $presentationUrl, string $feedbackUrl,
    string $emailSubject, string $utmString, string $utmCampaign,
    bool $dryRun
): array {
    $token = base64_encode($email . ':' . substr(md5($email . SITE_URL), 0, 16));
    $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=' . $token;

    // magic-link → quick-router → тихая регистрация → /pages/webinar-certificate.php?...&autopay=1
    if ($userId > 0) {
        $certificateUrl = generateMagicUrl(
            $userId,
            '/pages/webinar-cert-quick.php?webinar_id=' . (int)$webinar['id'],
            14,
            ['utm_source' => 'email', 'utm_medium' => 'recording', 'utm_campaign' => $utmCampaign]
        );
        $cabinetUrl = generateMagicUrl($userId, '/pages/cabinet.php?tab=events', 14);
    } else {
        $certificateUrl = SITE_URL . '/pages/webinar-cert-quick.php?webinar_id=' . (int)$webinar['id'];
        $cabinetUrl     = SITE_URL . '/pages/cabinet.php?tab=events';
    }

    // Переменные шаблона
    $email_subject     = $emailSubject;
    $utm               = $utmString;
    $user_name         = $name;
    $webinar_title     = $webinar['title'];
    $certificate_price = $webinar['certificate_price'] ?? 200;
    $certificate_hours = $webinar['certificate_hours'] ?? 2;
    $recording_url     = $recordingUrl;
    $presentation_url  = $presentationUrl;
    $feedback_url      = $feedbackUrl;
    $certificate_url   = $certificateUrl;
    $cabinet_url       = $cabinetUrl;
    $site_url          = SITE_URL;

    ob_start();
    include BASE_PATH . '/includes/email-templates/webinar_recording_osobyj_rebenok.php';
    $html = ob_get_clean();

    if ($dryRun) {
        echo "[DRY] -> {$email}\n  subject: {$email_subject}\n";
        return ['ok' => true, 'unisender_id' => null];
    }

    return EmailDispatcher::send([
        'to_email'        => $email,
        'to_name'         => $name,
        'subject'         => $email_subject,
        'html'            => $html,
        'unsubscribe_url' => $unsubscribeUrl,
        'meta'            => [
            'email_type'      => 'webinar',
            'touchpoint_code' => 'recording_mass',
            'user_id'         => $userId ?: null,
        ],
    ]);
}

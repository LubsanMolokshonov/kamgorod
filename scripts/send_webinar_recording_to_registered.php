<?php
/**
 * Разовая POST-webinar рассылка ЗАРЕГИСТРИРОВАННЫМ участникам:
 * запись вебинара + презентация эксперта + анкета обратной связи + предложение сертификата.
 * Через Unisender Go (EmailDispatcher).
 *
 * Парный скрипт к send_webinar_recording_invitation.php:
 *   - тот шлёт ХОЛОДНОЙ аудитории (кто не регистрировался),
 *   - этот — тем, КТО зарегистрировался на вебинар (webinar_registrations, status='registered').
 *
 * Своя таблица webinar_recording_registered_log (миграция 118), свой шаблон
 * (webinar_recording_kriterialnoe.php).
 *
 * Режимы:
 *   --slug=...            slug вебинара (обяз.)
 *   --populate            заполнить webinar_recording_registered_log (idempotent)
 *   --send                отправить пачку
 *   --batch=N             сколько за прогон (default 50)
 *   --test=email@host     одна отправка указанному адресу (без БД)
 *   --dry-run             не отправлять, только показать
 *   --pause / --resume    флаг паузы /tmp/webinar_recording_registered.pause
 *   --status              краткая статистика
 *
 * Рассылка разовая — cron не нужен, --send повторяют вручную до «No pending rows».
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

$slug      = $args['slug']  ?? null;
$batch     = (int)($args['batch'] ?? 50);
$dryRun    = !empty($args['dry-run']);
$testEmail = $args['test']  ?? null;

$LOCK  = '/tmp/webinar_recording_registered.lock';
$PAUSE = '/tmp/webinar_recording_registered.pause';

if (!empty($args['pause']))  { touch($PAUSE); echo "Paused.\n"; exit(0); }
if (!empty($args['resume'])) { @unlink($PAUSE); echo "Resumed.\n"; exit(0); }

if (!$slug) { fwrite(STDERR, "ERROR: --slug required\n"); exit(1); }

$wStmt = $db->prepare("SELECT * FROM webinars WHERE slug = ? LIMIT 1");
$wStmt->execute([$slug]);
$webinar = $wStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) { fwrite(STDERR, "Webinar not found: {$slug}\n"); exit(1); }
$webinarId = (int)$webinar['id'];

// === Параметры рассылки (привязаны к slug kriterialnoe-ocenivanie-7-instrumentov) ===
$RECORDING_URL    = 'https://clck.ru/3Tm8iG';
$PRESENTATION_URL = 'https://clck.ru/3Tm8kk';
$FEEDBACK_URL     = 'https://clck.ru/3TSwiz';
$EMAIL_SUBJECT    = 'Запись вебинара «Критериальное оценивание» + презентация и подарок';
$UTM_CAMPAIGN     = 'recording_kriterialnoe';
$UTM_STRING       = 'utm_source=email&utm_medium=recording&utm_campaign=' . $UTM_CAMPAIGN;

// === --status ===
if (!empty($args['status'])) {
    $rows = $db->prepare("SELECT status, COUNT(*) c FROM webinar_recording_registered_log WHERE webinar_id=? GROUP BY status");
    $rows->execute([$webinarId]);
    echo "Webinar #{$webinarId} ({$webinar['title']})\n";
    foreach ($rows as $r) printf("  %-8s %d\n", $r['status'], $r['c']);
    exit(0);
}

// === --test (одна отправка, без БД; не требует строки в users) ===
if ($testEmail) {
    $u = $db->prepare("SELECT id, full_name FROM users WHERE email = ? LIMIT 1");
    $u->execute([$testEmail]);
    $row = $u->fetch(PDO::FETCH_ASSOC);
    $testName   = $row['full_name'] ?? 'Коллега';
    $testUserId = $row ? (int)$row['id'] : 0;
    $res = sendOne($testEmail, $testName, $testUserId, $webinar,
        $RECORDING_URL, $PRESENTATION_URL, $FEEDBACK_URL, $EMAIL_SUBJECT, $UTM_STRING, $UTM_CAMPAIGN, $dryRun);
    echo "[TEST SENT] {$testEmail} unisender_id=" . ($res['unisender_id'] ?? '') . "\n";
    exit(0);
}

// === --populate ===
if (!empty($args['populate'])) {
    echo "Populating webinar_recording_registered_log for webinar #{$webinarId}...\n";
    // Кандидаты: все зарегистрированные на этот вебинар, кроме отписавшихся.
    $sql = "
        INSERT IGNORE INTO webinar_recording_registered_log
            (webinar_id, webinar_registration_id, user_id, email, status)
        SELECT wr.webinar_id, wr.id, wr.user_id, wr.email, 'pending'
        FROM webinar_registrations wr
        WHERE wr.webinar_id = ?
          AND wr.status = 'registered'
          AND wr.email IS NOT NULL AND wr.email <> ''
          AND wr.email NOT IN (SELECT email FROM email_unsubscribes)
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

if (file_exists($PAUSE)) { echo "Paused (flag at {$PAUSE}).\n"; exit(0); }

$lockFp = fopen($LOCK, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    echo "Another run is in progress (lock).\n"; exit(0);
}
register_shutdown_function(function() use ($lockFp, $LOCK) {
    if ($lockFp) { @flock($lockFp, LOCK_UN); @fclose($lockFp); }
    @unlink($LOCK);
});

$pick = $db->prepare("
    SELECT l.id, l.user_id, l.email, wr.full_name
    FROM webinar_recording_registered_log l
    JOIN webinar_registrations wr ON wr.id = l.webinar_registration_id
    WHERE l.webinar_id = ? AND l.status = 'pending'
    ORDER BY l.id
    LIMIT {$batch}
");
$pick->execute([$webinarId]);
$rows = $pick->fetchAll(PDO::FETCH_ASSOC);

if (!$rows) { echo "No pending rows.\n"; exit(0); }

echo "[" . date('Y-m-d H:i:s') . "] Webinar #{$webinarId} (recording → registered): sending up to " . count($rows) . "\n";

$sent = $failed = $skipped = 0;
foreach ($rows as $r) {
    $email  = $r['email'];
    $name   = $r['full_name'] ?: 'Коллега';
    $userId = (int)$r['user_id'];

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        markStatus($db, (int)$r['id'], 'skipped', 'invalid_email');
        $skipped++;
        continue;
    }

    // re-check: вдруг отписался за время в очереди
    $check = $db->prepare("SELECT 1 FROM email_unsubscribes WHERE email = ? LIMIT 1");
    $check->execute([$email]);
    if ($check->fetchColumn()) {
        markStatus($db, (int)$r['id'], 'skipped', 'unsubscribed');
        $skipped++;
        echo "[SKIP] {$email} — отписан\n";
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
        $upd = $db->prepare("UPDATE webinar_recording_registered_log SET status='sent', sent_at=NOW(), unisender_id=? WHERE id=?");
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
    $stmt = $db->prepare("UPDATE webinar_recording_registered_log SET status=?, error=?, sent_at=NOW() WHERE id=?");
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

    // Зарегистрированному с user_id — magic-link на оплату сертификата и в кабинет.
    // Без user_id — обычная ссылка на quick-router (тихая регистрация + редирект на оплату).
    if ($userId > 0) {
        $certificateUrl = generateMagicUrl(
            $userId,
            '/pages/webinar-certificate.php?webinar_id=' . (int)$webinar['id'],
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
    include BASE_PATH . '/includes/email-templates/webinar_recording_kriterialnoe.php';
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
            'touchpoint_code' => 'recording_registered',
            'user_id'         => $userId ?: null,
        ],
    ]);
}

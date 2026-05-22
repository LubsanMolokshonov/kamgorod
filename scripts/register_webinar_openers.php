<?php
/**
 * Авто-регистрация на вебинар тех, кто ОТКРЫЛ пригласительное письмо, но не зарегистрировался.
 *
 * Тёплая аудитория: приглашение на вебинар было разослано (touchpoint invitation_mass,
 * лог webinar_invitation_log), часть людей письмо открыла (email_events.opened_at), но
 * регистрацию не оформила. Скрипт регистрирует их на вебинар через WebinarRegistration::create()
 * — БЕЗ Bitrix24 и БЕЗ авто-писем WebinarEmailJourney (journey планируется только в
 * ajax/register-webinar.php, не в create()). Письмо с записью им рассылается отдельно
 * скриптом send_webinar_recording_to_registered.php.
 *
 * Режимы:
 *   --slug=...                      slug вебинара (обяз.)
 *   --invitation-touchpoint=CODE    touchpoint пригласительного письма (default invitation_mass)
 *   --dry-run                       только показать кандидатов, ничего не писать
 *   --status                        сколько уже зарегистрировано через этот скрипт
 */

if (php_sapi_name() !== 'cli') { die('CLI only'); }

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/WebinarRegistration.php';

set_time_limit(0);

const REGISTRATION_SOURCE = 'email_opener_recovery';

$args = [];
foreach (array_slice($argv, 1) as $a) {
    if (preg_match('/^--([a-z\-]+)(?:=(.*))?$/', $a, $m)) {
        $args[$m[1]] = $m[2] ?? true;
    }
}

$slug       = $args['slug'] ?? null;
$touchpoint = $args['invitation-touchpoint'] ?? 'invitation_mass';
$dryRun     = !empty($args['dry-run']);

if (!$slug) { fwrite(STDERR, "ERROR: --slug required\n"); exit(1); }

$wStmt = $db->prepare("SELECT * FROM webinars WHERE slug = ? LIMIT 1");
$wStmt->execute([$slug]);
$webinar = $wStmt->fetch(PDO::FETCH_ASSOC);
if (!$webinar) { fwrite(STDERR, "Webinar not found: {$slug}\n"); exit(1); }
$webinarId = (int)$webinar['id'];

// === --status ===
if (!empty($args['status'])) {
    $s = $db->prepare("SELECT COUNT(*) FROM webinar_registrations
                       WHERE webinar_id=? AND status='registered' AND registration_source=?");
    $s->execute([$webinarId, REGISTRATION_SOURCE]);
    echo "Webinar #{$webinarId} ({$webinar['title']})\n";
    echo "  зарегистрировано через register_webinar_openers: " . (int)$s->fetchColumn() . "\n";
    exit(0);
}

// === Окно кампании приглашений: MIN/MAX sent_at из webinar_invitation_log ±1 день ===
$win = $db->prepare("SELECT MIN(sent_at) mn, MAX(sent_at) mx
                     FROM webinar_invitation_log WHERE webinar_id=? AND status='sent'");
$win->execute([$webinarId]);
$w = $win->fetch(PDO::FETCH_ASSOC);
if (empty($w['mn'])) {
    fwrite(STDERR, "В webinar_invitation_log нет отправленных приглашений по вебинару #{$webinarId}\n");
    exit(1);
}
$from = date('Y-m-d', strtotime($w['mn'] . ' -1 day'));
$to   = date('Y-m-d', strtotime($w['mx'] . ' +2 day')); // +2: верхняя граница эксклюзивна

echo "Webinar #{$webinarId} ({$webinar['title']})\n";
echo "Окно кампании приглашений: {$w['mn']} … {$w['mx']}  →  выборка email_events [{$from} .. {$to})\n";

// === Кандидаты: открыли приглашение, ещё не зарегистрированы, не отписаны ===
$sql = "
    SELECT DISTINCT l.user_id, u.full_name, l.email
    FROM webinar_invitation_log l
    JOIN email_events e ON LOWER(e.recipient_email) = LOWER(l.email)
    JOIN users u ON u.id = l.user_id
    WHERE l.webinar_id = :wid AND l.status = 'sent'
      AND e.touchpoint_code = :tp
      AND e.sent_at >= :dfrom AND e.sent_at < :dto
      AND e.opened_at IS NOT NULL
      AND LOWER(l.email) NOT IN (
          SELECT LOWER(email) FROM webinar_registrations
          WHERE webinar_id = :wid2 AND status = 'registered'
      )
      AND LOWER(l.email) NOT IN (SELECT LOWER(email) FROM email_unsubscribes)
    ORDER BY l.user_id
";
$stmt = $db->prepare($sql);
$stmt->execute([
    ':wid' => $webinarId, ':tp' => $touchpoint,
    ':dfrom' => $from, ':dto' => $to, ':wid2' => $webinarId,
]);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Кандидатов (открыли приглашение, не зарегистрированы, не отписаны): " . count($candidates) . "\n";
if (!$candidates) { exit(0); }

$reg = new WebinarRegistration($db);

$registered = $skipped = $failed = 0;
foreach ($candidates as $c) {
    $email = trim($c['email']);
    $name  = trim((string)$c['full_name']) ?: 'Коллега';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "[SKIP] {$email} — невалидный email\n";
        $skipped++;
        continue;
    }
    if ($reg->isRegistered($webinarId, $email)) {
        echo "[SKIP] {$email} — уже зарегистрирован\n";
        $skipped++;
        continue;
    }
    if ($dryRun) {
        echo "[DRY] {$email} — {$name} (user #{$c['user_id']})\n";
        $registered++;
        continue;
    }
    try {
        $reg->create([
            'webinar_id'          => $webinarId,
            'user_id'             => (int)$c['user_id'],
            'full_name'           => $name,
            'email'               => $email,
            'registration_source' => REGISTRATION_SOURCE,
            'utm_source'          => 'email',
            'utm_medium'          => 'invite',
            'utm_campaign'        => 'webinar-kriterialnoe-may2026',
            'skip_bitrix24'       => true,
        ]);
        echo "[REG] {$email} — {$name}\n";
        $registered++;
    } catch (\Throwable $e) {
        echo "[FAIL] {$email} — " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo ($dryRun ? "DRY-RUN. " : "Done. ")
   . "registered={$registered} skipped={$skipped} failed={$failed}\n";
exit(0);

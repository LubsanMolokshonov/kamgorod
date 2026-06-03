<?php
/**
 * Разовый скрипт: ответы пользователям по неотвеченным support_alerts (срез на 01.06.2026).
 *
 * Контекст: на 27-28 мая был всплеск жалоб «ошибка 500 / не открывается кабинет /
 * не пришёл диплом». По факту кабинет 500 не отдавал — у людей истёк magic-link из
 * старого письма, а документы давно готовы и лежат в кабинете. Этот скрипт:
 *   - по КАЖДОМУ алерту (по id) шлёт персональный ответ через EmailDispatcher (Unisender Go);
 *   - где документ готов — ПРИКЛАДЫВАЕТ сам PDF (диплом/сертификат), резолвит проблему сразу;
 *   - всегда даёт свежий magic-link на /kabinet/ (14 дней);
 *   - логирует в alert_messages (direction=outbound);
 *   - переводит статус алерта (resolved — если приложили доки/дали прямой ответ;
 *     in_progress — если ждём уточнения от пользователя).
 *
 * Группы:
 *   A (resolve): документы готовы — прикладываем PDF + magic-link → status=resolved.
 *   A-info (resolve): вопрос (а не жалоба) — даём прямой ответ → status=resolved.
 *   C (await):  нужна инфа от пользователя — задаём уточняющий вопрос → status=in_progress.
 *
 * Запуск (на проде, внутри docker pedagogy_web):
 *   docker exec pedagogy_web php /var/www/html/scripts/reply_alerts_batch_20260603.php            (DRY-RUN)
 *   docker exec pedagogy_web php /var/www/html/scripts/reply_alerts_batch_20260603.php --send      (живая отправка)
 *   docker exec pedagogy_web php /var/www/html/scripts/reply_alerts_batch_20260603.php --send --only=107,122  (точечно)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../includes/magic-link-helper.php';
require_once __DIR__ . '/../includes/email-helper.php';
require_once __DIR__ . '/../classes/User.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

$argvFlags = array_slice($argv, 1);
$send      = in_array('--send', $argvFlags, true);
$dryRun    = !$send;
$onlyIds   = [];
foreach ($argvFlags as $f) {
    if (str_starts_with($f, '--only=')) {
        $onlyIds = array_filter(array_map('intval', explode(',', substr($f, 7))));
    }
}

$THROTTLE_US = 400_000;
$mode = $dryRun ? 'DRY-RUN' : 'SEND';
echo "=== reply_alerts_batch_20260603.php [$mode] ===\n";
echo "Now: " . date('Y-m-d H:i:s') . "\n\n";

$DIPLOMAS_DIR = BASE_PATH . '/uploads/diplomas/';
$WEBINAR_CERT_DIR = BASE_PATH . '/uploads/webinars/certificates/';
$PUB_CERT_DIR = BASE_PATH . '/uploads/publications/certificates/';

/**
 * Каждый кейс: alert_id => [
 *   'verdict'      => 'resolve'|'await',
 *   'intro_lines'  => string  (основной текст, plain; \n\n между абзацами),
 *   'attach'       => array вызовов-резолверов вложений (см. ниже),
 *   'with_link'    => bool    (давать ли magic-link; для чистых вопросов можно false),
 * ]
 * Резолверы вложений — массивы вида:
 *   ['diploma', registration_id, recipient_type, 'Имя_файла.pdf']
 *   ['olymp',   olympiad_registration_id, recipient_type, 'Имя_файла.pdf']
 *   ['webcert', webinar_certificate_id, 'Имя_файла.pdf']
 *   ['pubcert', publication_certificate_id, 'Имя_файла.pdf']
 */
$CASES = require __DIR__ . '/reply_alerts_batch_20260603_cases.php';

if ($onlyIds) {
    $CASES = array_intersect_key($CASES, array_flip($onlyIds));
}

$userObj = new User($db);
$sent = 0; $skip = 0; $err = 0;

foreach ($CASES as $alertId => $case) {
    echo "────────────────────────────────────────────\n";
    // Тянем алерт
    $a = $db->prepare("SELECT * FROM support_alerts WHERE id=? LIMIT 1");
    $a->execute([$alertId]);
    $alert = $a->fetch(PDO::FETCH_ASSOC);
    if (!$alert) { echo "[#$alertId] НЕ НАЙДЕН — пропуск\n"; $skip++; continue; }

    $email = mb_strtolower(trim((string)$alert['user_email']));
    $verdict = $case['verdict'];
    echo "[#$alertId] {$alert['source']}/{$alert['status']} → $email  [verdict=$verdict]\n";

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { echo "  SKIP: невалидный email\n"; $skip++; continue; }

    // user_id + имя
    $userId = (int)($alert['user_id'] ?? 0);
    $userName = trim((string)$alert['user_name']);
    if ($userName === '' || $userName === 'Пользователь чата' || $userName === 'Email-обращение') {
        $u = $userId ? $userObj->getById($userId) : $userObj->findByEmail($email);
        if ($u) {
            $userId = $userId ?: (int)$u['id'];
            $userName = (string)($u['full_name'] ?? '');
        }
    }
    if ($userId === 0) {
        $u = $userObj->findByEmail($email);
        if ($u) $userId = (int)$u['id'];
    }
    $toName = $userName !== '' ? $userName : 'Коллега';
    echo "  получатель: $toName <$email>" . ($userId ? " (user_id=$userId)" : " (нет в users)") . "\n";

    // magic-link
    $magicUrl = null;
    if (!empty($case['with_link'])) {
        $utm = ['utm_source'=>'email','utm_medium'=>'support','utm_campaign'=>'alert_reply_0603','utm_content'=>'alert_'.$alertId];
        if ($userId) {
            $magicUrl = generateMagicUrl($userId, '/kabinet/', 14, $utm);
        } else {
            $magicUrl = SITE_URL . '/kabinet/login?' . http_build_query(array_merge(['email'=>$email], $utm));
        }
        echo "  magic-link: " . substr($magicUrl, 0, 80) . "…\n";
    }

    // Вложения
    $attachments = [];
    foreach ($case['attach'] ?? [] as $spec) {
        $resolved = resolveAttachment($db, $spec, $DIPLOMAS_DIR, $WEBINAR_CERT_DIR, $PUB_CERT_DIR);
        if ($resolved) {
            $attachments[] = $resolved;
            echo "  вложение: {$resolved['name']}  (" . round(filesize($resolved['path'])/1024) . " КБ)\n";
        } else {
            echo "  [WARN] вложение не найдено: " . json_encode($spec, JSON_UNESCAPED_UNICODE) . "\n";
        }
    }

    // Текст письма
    [$subject, $text, $html] = buildLetter($case, $toName, $magicUrl, count($attachments) > 0);

    if ($dryRun) {
        echo "  ТЕМА: $subject\n";
        echo "  ───── plain-text ─────\n";
        foreach (explode("\n", $text) as $ln) echo "  | $ln\n";
        echo "  ──────────────────────\n";
        continue;
    }

    // Отправка
    try {
        $outboundMessageId = sprintf('<alert-%d-%d@%s>', $alertId, time(), parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro');
        $res = EmailDispatcher::send([
            'to_email'      => $email,
            'to_name'       => $toName,
            'subject'       => $subject,
            'html'          => $html,
            'text'          => $text,
            'attachments'   => $attachments ?: null,
            'reply_to'      => 'info@fgos.pro',
            'reply_to_name' => 'Каменный город',
            'extra_headers' => ['Message-ID' => $outboundMessageId],
            'meta'          => ['email_type'=>'other','touchpoint_code'=>'alert_reply_0603','user_id'=>$userId ?: null],
        ]);
        $sent++;
        echo "  [OK] отправлено, unisender_id=" . ($res['unisender_id'] ?? '?') . "\n";

        $attJson = $attachments ? json_encode(array_map(fn($x)=>['name'=>$x['name']], $attachments), JSON_UNESCAPED_UNICODE) : null;
        $db->prepare(
            "INSERT INTO alert_messages (alert_id,direction,from_email,from_name,to_email,subject,body_html,body_text,attachments_json,message_id,created_at)
             VALUES (?,'outbound','info@fgos.pro','Каменный город',?,?,?,?,?,?,NOW())"
        )->execute([$alertId, $email, $subject, $html, $text, $attJson, $outboundMessageId]);
        echo "  [OK] alert_messages row#" . $db->lastInsertId() . "\n";

        $newStatus = $verdict === 'resolve' ? 'resolved' : 'in_progress';
        $db->prepare("UPDATE support_alerts SET status=? WHERE id=?")->execute([$newStatus, $alertId]);
        echo "  [OK] status → $newStatus\n";

        usleep($THROTTLE_US);
    } catch (\Throwable $e) {
        $err++;
        echo "  [ERR] " . $e->getMessage() . "\n";
    }
}

echo "\n=== Итого: кейсов=" . count($CASES) . ", отправлено=$sent, пропущено=$skip, ошибок=$err ===\n";

// ============================================================================

function resolveAttachment(PDO $db, array $spec, string $dipDir, string $webDir, string $pubDir): ?array {
    [$kind] = $spec;
    $file = null;
    if ($kind === 'diploma') {
        [, $regId, $rtype, $name] = $spec;
        $s = $db->prepare("SELECT pdf_path FROM diplomas WHERE registration_id=? AND recipient_type=? ORDER BY generated_at DESC LIMIT 1");
        $s->execute([$regId, $rtype]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $file = $dipDir . basename($row['pdf_path']);
    } elseif ($kind === 'olymp') {
        [, $regId, $rtype, $name] = $spec;
        $s = $db->prepare("SELECT pdf_path FROM olympiad_diplomas WHERE olympiad_registration_id=? AND recipient_type=? ORDER BY generated_at DESC LIMIT 1");
        $s->execute([$regId, $rtype]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $file = $dipDir . basename($row['pdf_path']);
    } elseif ($kind === 'webcert') {
        [, $certId, $name] = $spec;
        $s = $db->prepare("SELECT pdf_path FROM webinar_certificates WHERE id=? LIMIT 1");
        $s->execute([$certId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $file = $webDir . basename($row['pdf_path']);
    } elseif ($kind === 'pubcert') {
        [, $certId, $name] = $spec;
        $s = $db->prepare("SELECT pdf_path FROM publication_certificates WHERE id=? LIMIT 1");
        $s->execute([$certId]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $file = $pubDir . basename($row['pdf_path']);
    }
    if ($file && is_readable($file)) {
        return ['path' => $file, 'name' => $spec[count($spec)-1]];
    }
    return null;
}

function buildLetter(array $case, string $toName, ?string $magicUrl, bool $hasAttach): array {
    $subject = $case['subject'] ?? 'По вашему обращению на fgos.pro';
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

    $greeting = "Здравствуйте, " . $toName . "!\n\n";
    $body = $case['intro_lines'];

    $linkBlock = '';
    if ($magicUrl) {
        $linkBlock = "\n\nВойти в личный кабинет без пароля (ссылка действует 14 дней):\n" . $magicUrl . "\n";
    }
    $attachNote = '';
    if ($hasAttach) {
        $attachNote = "\n\nВаши документы прикреплены к этому письму в формате PDF.";
    }
    $sign = "\n\nЕсли вопрос не решится — просто ответьте на это письмо, мы на связи.\n\nС уважением,\nкоманда «Каменный город» / fgos.pro\ninfo@fgos.pro";

    $text = $greeting . $body . $attachNote . $linkBlock . $sign;

    // HTML
    $bodyHtml = '<p style="margin:0 0 14px;">' . nl2br($h($body)) . '</p>';
    $attachHtml = $hasAttach
        ? '<div style="background:#ECFDF5;border-left:4px solid #10B981;padding:12px 16px;border-radius:6px;margin:16px 0;">Ваши документы <strong>прикреплены к этому письму</strong> в формате PDF.</div>'
        : '';
    $linkHtml = $magicUrl
        ? '<p style="margin:22px 0;text-align:center;">'
          . '<a href="' . $h($magicUrl) . '" style="display:inline-block;background:#10B981;color:#fff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Войти в личный кабинет</a></p>'
          . '<p style="margin:0 0 6px;color:#6B7280;font-size:13px;">Ссылка действует 14 дней, вход без пароля. Если кнопка не открывается, скопируйте адрес:</p>'
          . '<p style="margin:0 0 14px;color:#6B7280;font-size:12px;word-break:break-all;">' . $h($magicUrl) . '</p>'
        : '';

    $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;line-height:1.6;color:#1F2937;background:#f3f4f6;margin:0;padding:24px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#fff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.05);">'
        . '<div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px 28px;"><h2 style="margin:0;font-size:18px;font-weight:600;">Служба поддержки fgos.pro</h2></div>'
        . '<div style="padding:24px 28px;">'
        . '<p style="margin:0 0 14px;">Здравствуйте, ' . $h($toName) . '!</p>'
        . $bodyHtml
        . $attachHtml
        . $linkHtml
        . '<p style="margin:22px 0 0;color:#374151;">Если вопрос не решится — просто ответьте на это письмо, мы на связи.</p>'
        . '<p style="margin:14px 0 0;color:#374151;">С уважением,<br>команда «Каменный город» / fgos.pro<br>'
        . '<a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a></p>'
        . '</div></div></body></html>';

    return [$subject, $text, $html];
}

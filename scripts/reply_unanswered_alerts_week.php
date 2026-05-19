<?php
/**
 * Разовый скрипт: ответ пользователям по неотвеченным support_alerts за 7 дней.
 *
 * Логика:
 *   1. SELECT support_alerts (created_at >= NOW()-7d, ai_category in
 *      technical/access/payment/content) у которых либо нет outbound, либо
 *      последний inbound новее последнего outbound.
 *   2. Для каждого: подобрать шаблон письма по категории, добавить magic-link
 *      на /kabinet/ (14 дней).
 *   3. Отправить через EmailDispatcher (Unisender Go), записать в
 *      alert_messages.direction='outbound', статус new->in_progress.
 *
 * Источник: source IN ('email','ai_chat','manual','vk') — берём всё, что
 * содержит реальный user_email (исходный план был только email, но за неделю
 * email-алертов нет — ai_chat-жалобы тоже нужно отработать).
 *
 * Reply-headers (In-Reply-To/References) проставляем только если у алерта есть
 * inbound с message_id (т.е. источник email). Для ai_chat — обычное письмо.
 *
 * Запуск (на проде, внутри docker pedagogy_web):
 *   php /var/www/html/scripts/reply_unanswered_alerts_week.php --dry-run    (по умолчанию)
 *   php /var/www/html/scripts/reply_unanswered_alerts_week.php --send       (живая отправка)
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

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

$DAILY_CAP   = 10;
$THROTTLE_US = 300_000;
$ERR_LIMIT   = 5;

$BLOCKED_LOCAL = ['noreply', 'no-reply', 'mailer-daemon', 'postmaster', 'donotreply'];
$BLOCKED_DOMAIN_PARTS = ['unisender.com', 'unisender.ru'];

$mode = $dryRun ? 'DRY-RUN' : 'SEND';
echo "=== reply_unanswered_alerts_week.php [$mode] ===\n";
echo "Today: " . date('Y-m-d H:i:s') . "\n\n";

$sql = <<<SQL
SELECT
    sa.id, sa.source, sa.user_id, sa.user_email, sa.user_name,
    sa.ai_category, sa.ai_summary, sa.description,
    sa.status, sa.created_at,
    last_in.message_id  AS in_message_id,
    last_in.subject     AS in_subject,
    last_in.created_at  AS in_at,
    last_out.created_at AS out_at
FROM support_alerts sa
LEFT JOIN alert_messages last_in
       ON last_in.id = (SELECT id FROM alert_messages
                        WHERE alert_id = sa.id AND direction='inbound'
                        ORDER BY created_at DESC LIMIT 1)
LEFT JOIN alert_messages last_out
       ON last_out.id = (SELECT id FROM alert_messages
                         WHERE alert_id = sa.id AND direction='outbound'
                         ORDER BY created_at DESC LIMIT 1)
WHERE sa.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
  AND sa.ai_category IN ('technical','access','payment','content')
  AND sa.user_email IS NOT NULL
  AND sa.user_email <> ''
  AND (
        last_out.id IS NULL
     OR (last_in.created_at IS NOT NULL AND last_in.created_at > last_out.created_at)
  )
ORDER BY sa.created_at ASC
SQL;

$rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
echo "Найдено алертов: " . count($rows) . "\n\n";

$userObj = new User($db);
$seen = [];
$errCount = 0;
$sentCount = 0;
$skipCount = 0;

foreach ($rows as $row) {
    $alertId = (int)$row['id'];
    $email   = mb_strtolower(trim((string)$row['user_email']));
    echo "---\n[alert #$alertId] {$row['source']}/{$row['ai_category']}/{$row['status']} — $email\n";
    echo "  имя: " . ($row['user_name'] ?: '—') . "\n";
    echo "  создан: {$row['created_at']}\n";
    $descrPreview = mb_substr(preg_replace('/\s+/u', ' ', (string)$row['description']), 0, 200);
    echo "  суть: $descrPreview\n";

    // 1. Антидубль / адресный фильтр
    if (isset($seen[$email])) {
        echo "  SKIP: дубль email в этом запуске (уже обработан выше)\n";
        $skipCount++;
        continue;
    }
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    if (!$local || !$domain || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo "  SKIP: невалидный email\n";
        $skipCount++;
        continue;
    }
    if (in_array($local, $BLOCKED_LOCAL, true)) {
        echo "  SKIP: системный local-part\n";
        $skipCount++;
        continue;
    }
    foreach ($BLOCKED_DOMAIN_PARTS as $bd) {
        if (str_contains($domain, $bd)) {
            echo "  SKIP: системный домен\n";
            $skipCount++;
            continue 2;
        }
    }

    // 2. user_id (предпочитаем sa.user_id, fallback по email)
    $userId = (int)($row['user_id'] ?? 0);
    $userName = trim((string)$row['user_name']);
    if (!$userId) {
        $u = $userObj->findByEmail($email);
        if ($u) {
            $userId = (int)$u['id'];
            if (!$userName || $userName === 'Пользователь чата') {
                $userName = (string)($u['full_name'] ?? '');
            }
        }
    } else {
        $u = $userObj->getById($userId);
        if ($u && (!$userName || $userName === 'Пользователь чата')) {
            $userName = (string)($u['full_name'] ?? '');
        }
    }
    $toName = $userName !== '' ? $userName : 'Коллега';
    echo "  получатель: $toName <$email>" . ($userId ? " (user_id=$userId)" : " (нет в users)") . "\n";

    // 3. Magic-link
    $utm = [
        'utm_source'   => 'email',
        'utm_medium'   => 'support',
        'utm_campaign' => 'alert_reply_week',
        'utm_content'  => 'alert_' . $alertId,
    ];
    if ($userId) {
        $magicUrl = generateMagicUrl($userId, '/kabinet/', 14, $utm);
    } else {
        $magicUrl = SITE_URL . '/kabinet/login?'
            . http_build_query(array_merge(['email' => $email], $utm));
    }
    echo "  magic-link: $magicUrl\n";

    // 4. Шаблон письма
    [$html, $text, $subject] = buildLetter($row, $toName, $magicUrl);
    echo "  тема: $subject\n";

    // 5. Reply-headers (только если есть inbound message_id)
    $extraHeaders = [];
    $outboundMessageId = sprintf('<alert-%d-%d@%s>', $alertId, time(),
        parse_url(SITE_URL, PHP_URL_HOST) ?: 'fgos.pro');
    $extraHeaders['Message-ID'] = $outboundMessageId;
    if (!empty($row['in_message_id'])) {
        $inMid = trim((string)$row['in_message_id']);
        if ($inMid[0] !== '<') $inMid = '<' . $inMid . '>';
        $extraHeaders['In-Reply-To'] = $inMid;
        $extraHeaders['References']  = $inMid;
    }

    if ($dryRun) {
        echo "  [DRY-RUN] письмо НЕ отправлено\n";
        echo "  ───── plain-text preview ─────\n";
        foreach (explode("\n", $text) as $ln) echo "  | $ln\n";
        echo "  ──────────────────────────────\n";
        $seen[$email] = true;
        continue;
    }

    // 6. Daily-cap
    if (recipientReachedDailyCap($db, $email, $DAILY_CAP)) {
        echo "  SKIP: daily-cap ($DAILY_CAP) уже достигнут — пропуск\n";
        $skipCount++;
        continue;
    }

    // 7. Отправка
    try {
        $res = EmailDispatcher::send([
            'to_email'      => $email,
            'to_name'       => $toName,
            'subject'       => $subject,
            'html'          => $html,
            'text'          => $text,
            'reply_to'      => 'info@fgos.pro',
            'reply_to_name' => 'Каменный город',
            'extra_headers' => $extraHeaders,
            'meta'          => [
                'email_type'      => 'other',
                'touchpoint_code' => 'alert_reply_week',
                'user_id'         => $userId ?: null,
            ],
        ]);
        $sentCount++;
        $errCount = 0;
        $internalMid = $res['message_id'] ?? null;
        echo "  [OK] отправлено, internal_msg_id=$internalMid, unisender_id={$res['unisender_id']}\n";

        // 8. Лог в alert_messages
        $stmt = $db->prepare(
            "INSERT INTO alert_messages
                (alert_id, direction, from_email, from_name, to_email, subject,
                 body_html, body_text, message_id, in_reply_to, sent_by_admin_id, created_at)
             VALUES (?, 'outbound', ?, ?, ?, ?, ?, ?, ?, ?, NULL, NOW())"
        );
        $stmt->execute([
            $alertId,
            'info@fgos.pro',
            'Каменный город',
            $email,
            $subject,
            $html,
            $text,
            $outboundMessageId,
            $extraHeaders['In-Reply-To'] ?? null,
        ]);
        echo "  [OK] alert_messages row#" . $db->lastInsertId() . "\n";

        // 9. Перевод статуса new -> in_progress (resolved/closed не трогаем)
        if ($row['status'] === 'new') {
            $db->prepare("UPDATE support_alerts SET status='in_progress' WHERE id=? AND status='new'")
               ->execute([$alertId]);
            echo "  [OK] status: new -> in_progress\n";
        }

        $seen[$email] = true;
        usleep($THROTTLE_US);
    } catch (\Throwable $e) {
        $errCount++;
        echo "  [ERR] " . $e->getMessage() . "\n";
        if ($errCount >= $ERR_LIMIT) {
            fwrite(STDERR, "Превышен порог ошибок ($ERR_LIMIT подряд) — стоп.\n");
            break;
        }
    }
}

echo "\n=== Итого: алертов=" . count($rows)
   . ", отправлено=$sentCount, пропущено=$skipCount, ошибок=$errCount ===\n";

// =====================================================================

function buildLetter(array $row, string $toName, string $magicUrl): array {
    $cat = (string)$row['ai_category'];
    $descr = trim((string)$row['description']);
    $hasInbound = !empty($row['in_message_id']);
    $subject = $hasInbound
        ? 'Re: ' . ($row['in_subject'] ?: 'ваше обращение в поддержку')
        : 'По вашему обращению на сайт fgos.pro';

    $intro = "Здравствуйте, " . htmlspecialchars($toName, ENT_QUOTES, 'UTF-8') . "!\n\n"
           . "Спасибо, что написали нам — извините, что ответ занял время.\n\n";

    switch ($cat) {
        case 'access':
            $body = "Мы видим вашу обращение по поводу доступа в личный кабинет. "
                  . "Чтобы войти без пароля, перейдите по прямой ссылке ниже — она "
                  . "автоматически авторизует вас в кабинете. Ссылка действует 14 дней.\n";
            break;
        case 'technical':
            $body = "Мы получили ваше сообщение о технической проблеме на сайте. "
                  . "Команда уже в курсе. Чтобы быстрее разобраться, пожалуйста, проверьте — "
                  . "возможно, проблема ушла после обновления страницы. По прямой ссылке "
                  . "ниже вы войдёте в свой личный кабинет (без пароля, 14 дней).\n\n"
                  . "Если ошибка повторится — просто ответьте на это письмо и приложите, "
                  . "если можно, скриншот и адрес страницы. Мы ответим в этой же переписке.\n";
            break;
        case 'payment':
            $body = "Мы видим ваше обращение по поводу оплаты. Проверили платежи на вашем "
                  . "аккаунте — все документы должны быть доступны в личном кабинете. "
                  . "Прямая ссылка ниже откроет кабинет без пароля (действует 14 дней) — "
                  . "посмотрите, пожалуйста, раздел «Мои документы» / «Мои курсы».\n\n"
                  . "Если документа всё ещё нет, или вы видите двойное списание — ответьте "
                  . "на это письмо, укажите номер заказа (ORD-…) или дату оплаты, и мы "
                  . "разберёмся в течение рабочего дня.\n";
            break;
        case 'content':
        default:
            $body = "Мы получили ваше сообщение о проблеме с документом или содержимым "
                  . "в кабинете. По прямой ссылке ниже вы сразу попадёте в свой личный "
                  . "кабинет (без пароля, 14 дней) — пожалуйста, проверьте, появился ли "
                  . "нужный документ.\n\n"
                  . "Если документа нет, или в нём ошибка (фамилия/название работы и т.п.) — "
                  . "просто ответьте на это письмо и опишите, что нужно поправить. "
                  . "Мы исправим и пришлём корректную версию.\n";
            break;
    }

    $linkLine = "\nВойти в личный кабинет: " . $magicUrl . "\n";
    $signature = "\nС уважением,\nкоманда «Каменный город»\ninfo@fgos.pro\n";

    if ($descr !== '' && mb_strlen($descr) <= 400) {
        $cite = "\n— — —\nВаше исходное обращение:\n«" . $descr . "»\n— — —\n";
    } else {
        $cite = "";
    }

    $text = $intro . $body . $linkLine . $cite . $signature;

    $h = function (string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); };
    $bodyHtml = nl2br($h($body));
    $citeHtml = $cite !== '' ? '<blockquote style="border-left:3px solid #d1d5db;padding:8px 12px;color:#6b7280;margin:16px 0;background:#f9fafb;border-radius:4px;">'
        . nl2br($h(trim($cite, "\n— "))) . '</blockquote>' : '';

    $html = '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"></head>'
        . '<body style="font-family:Segoe UI,Tahoma,Geneva,Verdana,sans-serif;line-height:1.6;color:#1F2937;background:#f3f4f6;margin:0;padding:24px;">'
        . '<div style="max-width:600px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.05);">'
        . '<div style="background:linear-gradient(135deg,#667eea,#764ba2);color:#fff;padding:20px 28px;">'
        . '<h2 style="margin:0;font-size:18px;font-weight:600;">Ответ службы поддержки</h2></div>'
        . '<div style="padding:24px 28px;">'
        . '<p style="margin:0 0 14px;">Здравствуйте, ' . $h($toName) . '!</p>'
        . '<p style="margin:0 0 14px;">Спасибо, что написали нам — извините, что ответ занял время.</p>'
        . '<p style="margin:0 0 18px;">' . $bodyHtml . '</p>'
        . '<p style="margin:24px 0;text-align:center;">'
        . '<a href="' . $h($magicUrl) . '" style="display:inline-block;background:#10B981;color:#ffffff;text-decoration:none;padding:12px 28px;border-radius:8px;font-weight:600;">Войти в личный кабинет</a>'
        . '</p>'
        . '<p style="margin:0 0 6px;color:#6B7280;font-size:13px;">Ссылка действует 14 дней. Войти без пароля. Если кнопка не работает, скопируйте адрес:</p>'
        . '<p style="margin:0 0 18px;color:#6B7280;font-size:12px;word-break:break-all;">' . $h($magicUrl) . '</p>'
        . $citeHtml
        . '<p style="margin:24px 0 0;color:#374151;">С уважением,<br>команда «Каменный город»<br>'
        . '<a href="mailto:info@fgos.pro" style="color:#667eea;">info@fgos.pro</a></p>'
        . '</div></div></body></html>';

    return [$html, $text, $subject];
}

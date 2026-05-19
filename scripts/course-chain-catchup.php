<?php
/**
 * Разовый скрипт: догоняющая email-цепочка для неоплаченных заявок на курсы.
 *
 * Из-за того, что ajax/course-enrollment.php не вызывает CourseEmailChain::scheduleForEnrollment(),
 * по заявкам со статусом 'new' не уходило ни одного письма цепочки дожима.
 *
 * Режимы:
 *   php course-chain-catchup.php preview <enrollment_id> <to_email>
 *       — отрендерить все 6 писем цепочки и отправить на <to_email> для просмотра.
 *   php course-chain-catchup.php list
 *       — показать заявки-кандидаты (status='new').
 *   php course-chain-catchup.php schedule [enrollment_id,enrollment_id,...]
 *       — запланировать цепочку (scheduled_at = СЕЙЧАС + delay) для указанных заявок
 *         (или для всех status='new', если список не задан). Дальше отправит cron.
 */

if (php_sapi_name() !== 'cli') {
    die('CLI only');
}
set_time_limit(0);

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
require_once BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Database.php';
require_once BASE_PATH . '/classes/CourseEmailChain.php';
require_once BASE_PATH . '/classes/CoursePriceAB.php';
require_once BASE_PATH . '/classes/EmailDispatcher.php';
require_once BASE_PATH . '/includes/magic-link-helper.php';

$pdo   = $GLOBALS['db'];          // raw PDO из config/database.php
$db    = new Database($pdo);      // обёртка для запросов в этом скрипте
$chain = new CourseEmailChain($pdo);
$mode  = $argv[1] ?? '';

/** Активные touchpoint'ы, по возрастанию задержки. */
function activeTouchpoints(Database $db): array {
    return $db->query(
        "SELECT * FROM course_email_touchpoints WHERE is_active = 1 ORDER BY delay_minutes ASC"
    );
}

/** Полные данные заявки + курса (как в processPendingEmails). */
function loadEnrollment(Database $db, int $id): ?array {
    return $db->queryOne(
        "SELECT ce.id AS enrollment_id, ce.email, ce.full_name, ce.user_id,
                ce.course_id, ce.ab_variant, ce.created_at, ce.status,
                c.title AS course_title, c.price AS course_price,
                c.hours AS course_hours, c.program_type AS course_program_type,
                c.slug AS course_slug
         FROM course_enrollments ce
         JOIN courses c ON ce.course_id = c.id
         WHERE ce.id = ?",
        [$id]
    );
}

/**
 * Собрать subject + html письма touchpoint'а — повторяет логику CourseEmailChain::sendChainEmail.
 */
function buildEmail(CourseEmailChain $chain, array $enr, array $tp): array {
    $sender = CourseEmailChain::pickPersonalSender($enr['email']);

    $unsubscribeToken = $chain->generateUnsubscribeToken($enr['email']);
    $unsubscribeUrl   = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

    $abVariant = $enr['ab_variant'] ?? 'A';
    $basePrice = floatval($enr['course_price']);
    $abPrice   = class_exists('CoursePriceAB')
        ? CoursePriceAB::getAdjustedPrice($basePrice, $abVariant, $enr['course_program_type'] ?? null)
        : $basePrice;

    $journeyUtm = [
        'utm_source'   => 'email',
        'utm_medium'   => 'trigger',
        'utm_campaign' => 'course_chain',
        'utm_content'  => 'delay_' . (int)$tp['delay_minutes'],
    ];

    $paymentUrl = generateMagicUrl($enr['user_id'], '/kabinet/?tab=courses', 7, $journeyUtm);

    $discountUrl = null;
    $discountPrice = null;
    if ((int)$tp['delay_minutes'] >= 1440) {
        $discountToken = CourseEmailChain::generateDiscountToken($enr['enrollment_id'], 48);
        $discountUrl = generateMagicUrl(
            $enr['user_id'],
            '/kabinet/?tab=courses&discount_token=' . urlencode($discountToken),
            7,
            array_merge($journeyUtm, ['utm_content' => 'discount_' . (int)$tp['delay_minutes']])
        );
        $discountPrice = round($abPrice * 0.9);
    }

    $programLabel = $enr['course_program_type'] === 'pp'
        ? 'Профессиональная переподготовка' : 'Повышение квалификации';
    $documentLabel = $enr['course_program_type'] === 'pp'
        ? 'Диплом о профессиональной переподготовке' : 'Удостоверение о повышении квалификации';

    $templateData = [
        'user_name'           => $enr['full_name'],
        'user_email'          => $enr['email'],
        'user_id'             => $enr['user_id'],
        'course_title'        => $enr['course_title'],
        'course_price'        => $abPrice,
        'course_hours'        => $enr['course_hours'],
        'course_program_type' => $enr['course_program_type'],
        'program_label'       => $programLabel,
        'document_label'      => $documentLabel,
        'course_url'          => SITE_URL . '/kursy/' . $enr['course_slug'] . '/',
        'payment_url'         => $paymentUrl,
        'discount_url'        => $discountUrl,
        'discount_price'      => $discountPrice,
        'unsubscribe_url'     => $unsubscribeUrl,
        'site_url'            => SITE_URL,
        'site_name'           => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
        'footer_reason'       => 'Вы получили это письмо, потому что подали заявку на курс на портале fgos.pro',
        '_sender_name'        => CourseEmailChain::extractFirstName($sender['from_name']),
    ];

    $templatePath = BASE_PATH . '/includes/email-templates/' . $tp['email_template'] . '.php';
    if (!file_exists($templatePath)) {
        throw new \Exception('Template not found: ' . $tp['email_template']);
    }
    extract($templateData);
    ob_start();
    include $templatePath;
    $html = ob_get_clean();

    $subject = str_replace(
        ['{course_title}', '{user_name}'],
        [$enr['course_title'], $enr['full_name']],
        $tp['email_subject']
    );

    return [
        'subject'         => $subject,
        'html'            => $html,
        'sender'          => $sender,
        'unsubscribe_url' => $unsubscribeUrl,
    ];
}

// ──────────────────────────────────────────────
if ($mode === 'list') {
    $rows = $db->query(
        "SELECT ce.id, ce.full_name, ce.email, ce.created_at, c.title
         FROM course_enrollments ce JOIN courses c ON ce.course_id = c.id
         WHERE ce.status = 'new' AND ce.id >= 888888
         ORDER BY ce.id DESC"
    );
    foreach ($rows as $r) {
        echo sprintf("#%d | %s | %s | %s\n", $r['id'], $r['email'], $r['created_at'], $r['full_name']);
    }
    echo count($rows) . " заявок\n";
    exit;
}

// ──────────────────────────────────────────────
if ($mode === 'preview') {
    $enrollmentId = (int)($argv[2] ?? 0);
    $toEmail      = $argv[3] ?? '';
    if (!$enrollmentId || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        die("Usage: php course-chain-catchup.php preview <enrollment_id> <to_email>\n");
    }
    $enr = loadEnrollment($db, $enrollmentId);
    if (!$enr) {
        die("Enrollment #{$enrollmentId} not found\n");
    }

    $touchpoints = activeTouchpoints($db);
    $n = 0;
    $total = 0;
    foreach ($touchpoints as $tp) {
        if (!empty($tp['bitrix_only'])) { continue; }
        $total++;
    }
    foreach ($touchpoints as $tp) {
        if (!empty($tp['bitrix_only'])) { continue; }
        $n++;
        $built = buildEmail($chain, $enr, $tp);
        EmailDispatcher::send([
            'to_email'        => $toEmail,
            'to_name'         => 'Превью',
            'subject'         => sprintf('[ПРЕВЬЮ %d/%d · %s] %s', $n, $total, $tp['code'], $built['subject']),
            'html'            => $built['html'],
            'from_name'       => $built['sender']['from_name'],
            'reply_to'        => $built['sender']['reply_to'],
            'reply_to_name'   => $built['sender']['reply_to_name'],
            'unsubscribe_url' => $built['unsubscribe_url'],
            'meta'            => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . $tp['code']],
        ]);
        echo "PREVIEW SENT | {$n}/{$total} | {$tp['code']} → {$toEmail}\n";
    }
    echo "Готово: {$total} писем-превью отправлено на {$toEmail}\n";
    exit;
}

// ──────────────────────────────────────────────
if ($mode === 'schedule') {
    $ids = [];
    if (!empty($argv[2])) {
        $ids = array_filter(array_map('intval', explode(',', $argv[2])));
    } else {
        $rows = $db->query("SELECT id FROM course_enrollments WHERE status = 'new' AND id >= 888888");
        $ids = array_column($rows, 'id');
    }

    $touchpoints = activeTouchpoints($db);
    $now = time();
    $totalScheduled = 0;

    foreach ($ids as $id) {
        $enr = loadEnrollment($db, (int)$id);
        if (!$enr || $enr['status'] !== 'new') {
            echo "SKIP #{$id} | not found or not 'new'\n";
            continue;
        }
        if ($chain->isUnsubscribed($enr['email'])) {
            echo "SKIP #{$id} | {$enr['email']} unsubscribed\n";
            continue;
        }

        $count = 0;
        foreach ($touchpoints as $tp) {
            $existing = $db->queryOne(
                "SELECT id FROM course_email_log WHERE enrollment_id = ? AND touchpoint_id = ?",
                [$id, $tp['id']]
            );
            if ($existing) { continue; }

            $scheduledAt = date('Y-m-d H:i:s', $now + ((int)$tp['delay_minutes'] * 60));
            $db->insert('course_email_log', [
                'enrollment_id' => $id,
                'user_id'       => $enr['user_id'],
                'touchpoint_id' => $tp['id'],
                'email'         => $enr['email'],
                'status'        => 'pending',
                'scheduled_at'  => $scheduledAt,
            ]);
            $count++;
        }
        $totalScheduled += $count;
        echo "SCHEDULED #{$id} | {$enr['email']} | {$count} touchpoints\n";
    }
    echo "Итого: запланировано {$totalScheduled} писем для " . count($ids) . " заявок\n";
    exit;
}

die("Usage: php course-chain-catchup.php [list|preview|schedule]\n");

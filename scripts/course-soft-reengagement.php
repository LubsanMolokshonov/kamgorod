<?php
/**
 * Разовый скрипт: мягкая реактивация заявок со сделкой «в работе» (status=enrolled),
 * которые из-за инцидента 05.05–19.06.2026 не получили ни одного письма цепочки.
 *
 * Шлёт ОДНО письцо без цены и без скидки (шаблон course_soft_reengagement) напрямую
 * через EmailDispatcher — в обход status-gate в CourseEmailChain::processPendingEmails(),
 * который шлёт только status='new'. Тем самым НЕ трогает CRM-статус и НЕ двигает стадии Bitrix.
 *
 * Режимы:
 *   php course-soft-reengagement.php preview <enrollment_id> <to_email>
 *   php course-soft-reengagement.php send <id1,id2,...>
 *
 * Защита: повторная отправка тому же email не делается (проверка email_events).
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
require_once BASE_PATH . '/classes/EmailDispatcher.php';

$pdo  = $GLOBALS['db'];
$db   = new Database($pdo);
$mode = $argv[1] ?? '';

const TOUCHPOINT_CODE = 'course_soft_reengagement';
const EMAIL_TEMPLATE  = 'course_soft_reengagement';

function loadEnrollment(Database $db, int $id): ?array {
    return $db->queryOne(
        "SELECT ce.id AS enrollment_id, ce.email, ce.full_name, ce.user_id, ce.status,
                c.title AS course_title, c.hours AS course_hours,
                c.program_type AS course_program_type, c.slug AS course_slug
         FROM course_enrollments ce JOIN courses c ON ce.course_id = c.id
         WHERE ce.id = ?",
        [$id]
    );
}

function alreadySent(Database $db, string $email): bool {
    $row = $db->queryOne(
        "SELECT id FROM email_events
         WHERE recipient_email = ? AND touchpoint_code = ? LIMIT 1",
        [$email, TOUCHPOINT_CODE]
    );
    return !empty($row);
}

function buildSoftEmail(CourseEmailChain $chain, array $enr): array {
    $sender = CourseEmailChain::pickPersonalSender($enr['email']);

    $unsubscribeToken = $chain->generateUnsubscribeToken($enr['email']);
    $unsubscribeUrl   = SITE_URL . '/pages/unsubscribe.php?token=' . urlencode($unsubscribeToken);

    $programLabel = $enr['course_program_type'] === 'pp'
        ? 'Профессиональная переподготовка' : 'Повышение квалификации';
    $documentLabel = $enr['course_program_type'] === 'pp'
        ? 'Диплом о профессиональной переподготовке' : 'Удостоверение о повышении квалификации';

    $templateData = [
        'user_name'       => $enr['full_name'],
        'course_title'    => $enr['course_title'],
        'course_hours'    => $enr['course_hours'],
        'program_label'   => $programLabel,
        'document_label'  => $documentLabel,
        'course_url'      => SITE_URL . '/kursy/' . $enr['course_slug'] . '/',
        'unsubscribe_url' => $unsubscribeUrl,
        'site_url'        => SITE_URL,
        'site_name'       => defined('SITE_NAME') ? SITE_NAME : 'ФГОС-Практикум',
        'footer_reason'   => 'оставили заявку на курс на портале fgos.pro',
        'email_subject'   => 'Остались вопросы по курсу?',
        '_sender_name'    => CourseEmailChain::extractFirstName($sender['from_name']),
    ];

    $templatePath = BASE_PATH . '/includes/email-templates/' . EMAIL_TEMPLATE . '.php';
    if (!file_exists($templatePath)) {
        throw new \Exception('Template not found: ' . EMAIL_TEMPLATE);
    }
    extract($templateData);
    ob_start();
    include $templatePath;
    $html = ob_get_clean();

    return [
        'subject'         => 'Остались вопросы по курсу «' . $enr['course_title'] . '»?',
        'html'            => $html,
        'sender'          => $sender,
        'unsubscribe_url' => $unsubscribeUrl,
    ];
}

$chain = new CourseEmailChain($pdo);

// ──────────────────────────────────────────────
if ($mode === 'preview') {
    $enrollmentId = (int)($argv[2] ?? 0);
    $toEmail      = $argv[3] ?? '';
    if (!$enrollmentId || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        die("Usage: php course-soft-reengagement.php preview <enrollment_id> <to_email>\n");
    }
    $enr = loadEnrollment($db, $enrollmentId);
    if (!$enr) { die("Enrollment #{$enrollmentId} not found\n"); }

    $built = buildSoftEmail($chain, $enr);
    EmailDispatcher::send([
        'to_email'        => $toEmail,
        'to_name'         => 'Превью',
        'subject'         => '[ПРЕВЬЮ] ' . $built['subject'],
        'html'            => $built['html'],
        'from_name'       => $built['sender']['from_name'],
        'reply_to'        => $built['sender']['reply_to'],
        'reply_to_name'   => $built['sender']['reply_to_name'],
        'unsubscribe_url' => $built['unsubscribe_url'],
        'meta'            => ['email_type' => 'other', 'touchpoint_code' => 'preview_' . TOUCHPOINT_CODE],
    ]);
    echo "PREVIEW SENT → {$toEmail}\n";
    exit;
}

// ──────────────────────────────────────────────
if ($mode === 'send') {
    if (empty($argv[2])) {
        die("Usage: php course-soft-reengagement.php send <id1,id2,...>\n");
    }
    $ids = array_filter(array_map('intval', explode(',', $argv[2])));

    $sent = 0; $skipped = 0; $failed = 0;
    foreach ($ids as $id) {
        $enr = loadEnrollment($db, (int)$id);
        if (!$enr) { echo "SKIP #{$id} | not found\n"; $skipped++; continue; }
        if (in_array($enr['status'], ['paid', 'cancelled'], true)) {
            echo "SKIP #{$id} | status={$enr['status']}\n"; $skipped++; continue;
        }
        if ($chain->isUnsubscribed($enr['email'])) {
            echo "SKIP #{$id} | {$enr['email']} unsubscribed\n"; $skipped++; continue;
        }
        if (alreadySent($db, $enr['email'])) {
            echo "SKIP #{$id} | {$enr['email']} already got soft-reengagement\n"; $skipped++; continue;
        }

        try {
            $built = buildSoftEmail($chain, $enr);
            EmailDispatcher::send([
                'to_email'        => $enr['email'],
                'to_name'         => $enr['full_name'],
                'subject'         => $built['subject'],
                'html'            => $built['html'],
                'from_name'       => $built['sender']['from_name'],
                'reply_to'        => $built['sender']['reply_to'],
                'reply_to_name'   => $built['sender']['reply_to_name'],
                'unsubscribe_url' => $built['unsubscribe_url'],
                'meta'            => [
                    'email_type'      => 'course',
                    'touchpoint_code' => TOUCHPOINT_CODE,
                    'user_id'         => $enr['user_id'] ?? null,
                ],
            ]);
            echo "SENT #{$id} | {$enr['email']}\n";
            $sent++;
        } catch (\Throwable $e) {
            echo "FAIL #{$id} | {$enr['email']} | " . $e->getMessage() . "\n";
            $failed++;
        }
    }
    echo "Итого: sent={$sent}, skipped={$skipped}, failed={$failed}\n";
    exit;
}

die("Usage: php course-soft-reengagement.php [preview|send]\n");

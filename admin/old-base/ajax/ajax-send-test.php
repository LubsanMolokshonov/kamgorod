<?php
require_once __DIR__ . '/../../includes/auth.php'; // admin auth guard
/**
 * AJAX: тестовая отправка одного письма (по теме/телу/CTA кампании).
 * Не использует old_base_campaign_recipients и не меняет статистику.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../classes/Database.php';
require_once __DIR__ . '/../../../classes/Admin.php';
require_once __DIR__ . '/../../../classes/OldBaseCampaign.php';
require_once __DIR__ . '/../../../classes/EmailDispatcher.php';
require_once __DIR__ . '/../../../includes/session.php';

Admin::verifySession();
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validateCSRFToken($_POST['csrf_token'] ?? '')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'forbidden']);
    exit;
}

$id = (int)($_POST['campaign_id'] ?? 0);
$testEmail = trim($_POST['test_email'] ?? '');
if (!filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'invalid email']);
    exit;
}

try {
    $c = (new OldBaseCampaign($db))->find($id);
    if (!$c) throw new \RuntimeException('Кампания не найдена');

    $cta = $c['cta_url'] ?? '';
    if ($cta && !empty($c['auto_utm'])) {
        $cta = OldBaseCampaign::appendUtm($cta, $c['code']);
    }

    $unsubscribeUrl = SITE_URL . '/pages/unsubscribe.php?token=test';

    $map = [
        '{{name}}' => 'Тест',
        '{{email}}' => $testEmail,
        '{{cta_url}}' => $cta,
        '{{unsubscribe_url}}' => $unsubscribeUrl,
    ];
    $html = strtr($c['html_body'], $map);
    $text = $c['plain_body'] ? strtr($c['plain_body'], $map) : null;
    $subject = '[TEST] ' . strtr($c['subject'], ['{{name}}' => 'Тест']);

    $params = [
        'to_email' => $testEmail,
        'subject'  => $subject,
        'html'     => $html,
        'text'     => $text,
        'unsubscribe_url' => $unsubscribeUrl,
        'meta' => [
            'email_type'      => 'old_base',
            'touchpoint_code' => $c['code'] . '_test',
        ],
    ];
    if (!empty($c['from_name']))  $params['from_name']  = $c['from_name'];
    if (!empty($c['from_email'])) $params['from_email'] = $c['from_email'];

    EmailDispatcher::send($params);
    echo json_encode(['success' => true]);
} catch (\Throwable $e) {
    error_log('old-base test-send error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

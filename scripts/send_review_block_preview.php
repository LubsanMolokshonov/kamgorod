<?php
/**
 * Одноразовый превью-отправщик: рендерит payment-success письмо
 * с новым блоком «Оставьте отзыв» и шлёт на заданный адрес через Unisender Go.
 *
 * Запуск:
 *   php scripts/send_review_block_preview.php [email]
 * По умолчанию шлёт на lubsanmolokshonov@gmail.com.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/email-helper.php';

$to = $argv[1] ?? 'lubsanmolokshonov@gmail.com';

$order = [
    'order_number' => 'TEST-PREVIEW-001',
];
$user = [
    'full_name' => 'Лубсан Молокшонов',
    'email'     => $to,
];
$cabinetUrl = (defined('SITE_URL') ? SITE_URL : 'https://fgos.pro') . '/kabinet/';

$html = buildSuccessEmailBody($order, $user, $cabinetUrl);

try {
    EmailDispatcher::send([
        'to_email' => $to,
        'to_name'  => $user['full_name'],
        'subject'  => '[ПРЕВЬЮ] Письмо после оплаты + блок отзывов',
        'html'     => $html,
        'meta'     => [
            'email_type'      => 'preview',
            'touchpoint_code' => 'review_block_preview',
        ],
    ]);
    echo "OK, sent to {$to}\n";
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(1);
}

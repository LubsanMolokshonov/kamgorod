<?php
/**
 * Smoke-тест EmailDispatcher: отправка тестового письма через Unisender Go.
 *
 * Использование:
 *   docker exec pedagogy_web php scripts/test_unisender_dispatcher.php you@example.com
 *   php scripts/test_unisender_dispatcher.php you@example.com  (если PHP локально)
 *
 * Проверяет:
 *   1. UnisenderClient + EmailDispatcher формируют корректный запрос.
 *   2. Запись в email_events создаётся через EmailTracker::recordExternalSend.
 *   3. HTML-режим: пиксель и rewrite ссылок работают.
 *   4. Plain-text режим (флагом --text): голые URL переписываются.
 *   5. Вложение PDF (флагом --pdf=path): попадает в письмо.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/EmailDispatcher.php';

$to = $argv[1] ?? null;
if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php test_unisender_dispatcher.php <recipient> [--text] [--pdf=path]\n");
    exit(1);
}

$mode = 'html';
$pdfPath = null;
foreach (array_slice($argv, 2) as $arg) {
    if ($arg === '--text') $mode = 'text';
    if (str_starts_with($arg, '--pdf=')) $pdfPath = substr($arg, 6);
}

$attachments = [];
if ($pdfPath) {
    if (!is_file($pdfPath)) {
        fwrite(STDERR, "PDF not found: {$pdfPath}\n");
        exit(1);
    }
    $attachments[] = ['path' => $pdfPath, 'name' => basename($pdfPath)];
}

$subject = 'Smoke-тест EmailDispatcher (' . $mode . ($pdfPath ? '+pdf' : '') . ')';

$html = '<html><body>'
      . '<h2>Тест EmailDispatcher</h2>'
      . '<p>Это HTML-письмо отправлено через <strong>EmailDispatcher → UnisenderClient → Unisender Go</strong>.</p>'
      . '<p>Кликабельная ссылка для проверки rewrite: <a href="https://fgos.pro/olimpiady/">список олимпиад</a>.</p>'
      . '<p>Вторая ссылка: <a href="https://fgos.pro/kabinet/">личный кабинет</a>.</p>'
      . '<p>Unsubscribe: <a href="https://fgos.pro/pages/unsubscribe.php?token=test">отписаться</a> (не должна быть переписана).</p>'
      . '</body></html>';

$text = "Smoke-тест EmailDispatcher\n\n"
      . "Это plain-text письмо через EmailDispatcher → UnisenderClient → Unisender Go.\n\n"
      . "Голая ссылка для проверки rewrite: https://fgos.pro/olimpiady/\n"
      . "Вторая: https://fgos.pro/kabinet/\n\n"
      . "Unsubscribe (не должна быть переписана): https://fgos.pro/pages/unsubscribe.php?token=test\n";

$params = [
    'to_email'        => $to,
    'to_name'         => 'Тестовый получатель',
    'subject'         => $subject,
    'unsubscribe_url' => 'https://fgos.pro/pages/unsubscribe.php?token=test',
    'attachments'     => $attachments,
    'meta'            => [
        'email_type'      => 'other',
        'touchpoint_code' => 'smoke_test',
        'user_id'         => null,
    ],
];
if ($mode === 'html') {
    $params['html'] = $html;
    $params['text'] = $text;
} else {
    $params['text'] = $text;
}

try {
    $result = EmailDispatcher::send($params);
    echo "OK\n";
    echo "  message_id:   {$result['message_id']}\n";
    echo "  unisender_id: " . ($result['unisender_id'] ?? '(none)') . "\n";
    echo "  recipient:    {$to}\n";
    echo "  subject:      {$subject}\n";
    if ($attachments) echo "  attachments:  " . count($attachments) . "\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAIL: " . $e->getMessage() . "\n");
    exit(2);
}

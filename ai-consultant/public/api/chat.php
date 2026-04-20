<?php
require_once __DIR__ . '/../../src/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ai_json(['success' => false, 'error' => 'method_not_allowed'], 405);
}

$input = ai_read_json();

try {
    $service = new ChatService(ai_get_pdo());
    $result = $service->handle($input);
} catch (Throwable $e) {
    ai_log('CHAT', 'Unhandled exception', ['error' => $e->getMessage(), 'file' => basename($e->getFile()), 'line' => $e->getLine()]);
    ai_json(['success' => false, 'error' => 'internal_error', 'message' => 'Извините, произошла ошибка. Попробуйте ещё раз.'], 500);
}

ai_json($result, $result['success'] ?? false ? 200 : 400);

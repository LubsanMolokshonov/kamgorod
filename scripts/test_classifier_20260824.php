<?php
/**
 * Проверка промпта классификатора входящих писем на РЕАЛЬНЫХ обращениях.
 *
 * Берёт тела писем из support_alerts (там лежит исходный текст) и прогоняет
 * через PromptBuilder::buildEmailClassificationMessages + YandexGPT.
 * Ожидание печатается рядом с фактом — сразу видно регрессии.
 *
 * Повод: eLama дважды подряд (алерты 175, 197) заводился как payment-обращение.
 *
 * Запуск: docker exec pedagogy_web php /var/www/html/scripts/test_classifier_20260824.php
 */

declare(strict_types=1);
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// bootstrap ai-consultant читает ключи из ENV контейнера (они есть только в pedagogy_ai),
// а в pedagogy_web те же значения лежат константами из .env — прокидываем их в ENV.
foreach (['YANDEX_GPT_API_KEY', 'YANDEX_GPT_FOLDER_ID', 'YANDEX_GPT_MODEL'] as $c) {
    if (defined($c) && getenv($c) === false) { putenv($c . '=' . constant($c)); }
}

require_once __DIR__ . '/../ai-consultant/src/bootstrap.php';
// Классы ai-consultant без неймспейса — автозагрузчик bootstrap'а мапит имя в файл напрямую

// id алерта => [ожидаемый is_alert, краткая пометка]
$EXPECT = [
    197 => [false, 'eLama: счёт оплачен, 100k на баланс — биллинг рекламы'],
    175 => [false, 'eLama (первый ложный срабатыш 03.08)'],
    191 => [true,  'Курбатова: не могу скачать положение'],
    203 => [true,  'Маначина: оформила заявку, диплом не получила'],
    201 => [true,  'Смурова: не получается оплатить'],
    194 => [true,  'Афанасьева: письмо по обучению не нашла'],
    199 => [true,  'Башарова: документы для бухгалтерии'],
    196 => [true,  'Харитонова: неправильное ФИО в публикации'],
    190 => [true,  'Никулина: претензия к цене сертификата'],
    188 => [true,  'Оболенская: прошу удалить аккаунт'],
];

$ids = implode(',', array_map('intval', array_keys($EXPECT)));
$rows = $db->query("SELECT id, user_email, description FROM support_alerts WHERE id IN ($ids)")->fetchAll(PDO::FETCH_ASSOC);
$byId = [];
foreach ($rows as $r) { $byId[(int)$r['id']] = $r; }

$gpt = new YandexGPTClient(20);
$ok = 0; $bad = 0; $skip = 0;

printf("%-5s %-9s %-9s %-6s %s\n", 'ID', 'ожидаем', 'факт', 'conf', 'случай');
echo str_repeat('─', 100) . "\n";

foreach ($EXPECT as $id => [$expected, $label]) {
    if (!isset($byId[$id])) { echo "  [SKIP] алерт $id не найден\n"; $skip++; continue; }
    $row = $byId[$id];

    // В description первая строка часто «Тема: …» — отделяем её как subject
    $descr = (string)$row['description'];
    $subject = '';
    if (preg_match('/^Тема:\s*(.+)$/mu', $descr, $m)) { $subject = trim($m[1]); }

    try {
        $messages = PromptBuilder::buildEmailClassificationMessages($subject, $descr, (string)$row['user_email']);
        $resp = $gpt->complete($messages, 0.1, 250);
        if (!preg_match('/\{[\s\S]*\}/', $resp['text'], $mm)) { throw new RuntimeException('нет JSON в ответе'); }
        $parsed = json_decode($mm[0], true);
        $isAlert = !empty($parsed['is_alert']);
        $conf = (float)($parsed['confidence'] ?? 0);
        $hit = ($isAlert === $expected);
        $hit ? $ok++ : $bad++;
        printf("%-5d %-9s %-9s %-6.2f %s %s\n",
            $id,
            $expected ? 'alert' : 'НЕ alert',
            $isAlert ? 'alert' : 'НЕ alert',
            $conf,
            $hit ? '✓' : '✗ РЕГРЕССИЯ',
            $label
        );
        if (!$hit) { echo "        summary: " . mb_substr((string)($parsed['summary'] ?? ''), 0, 120) . "\n"; }
    } catch (\Throwable $e) {
        echo "  [ERR] $id: " . $e->getMessage() . "\n"; $skip++;
    }
    usleep(300000);
}

echo str_repeat('─', 100) . "\n";
echo "Совпало: $ok, регрессий: $bad, пропущено: $skip\n";

<?php
/**
 * Сброс и перезапуск A/B-теста модели оплаты (раунд 2).
 *
 * Зачем: после редизайна B-корзины старые данные раунда 1 (плохая корзина, конверсия 21.8%)
 * нельзя смешивать с новыми. Скрипт разводит эпохи:
 *   1) снимает СНАПШОТ раунда 1 (печатает + пишет в scripts/ab-snapshots/),
 *   2) обнуляет users.pricing_variant → залогиненные переразбиваются заново при следующем визите,
 *   3) генерирует новый PRICING_AB_SECRET → инвалидирует cookie pm_v анонимов (тоже переразбивка),
 *   4) подсказывает выставить PRICING_AB_EPOCH=<сегодня> в .env (дашборд /admin/ab-test учтёт
 *      заказы только с этой даты).
 *
 * ⚠️ ЗАПУСКАТЬ ТОЛЬКО ПОСЛЕ деплоя редизайна B-корзины. Иначе перезапустим тест на старой корзине.
 *
 * Использование (в контейнере):
 *   docker exec pedagogy_web php /var/www/html/scripts/reset-pricing-ab.php           # сухой прогон
 *   docker exec pedagogy_web php /var/www/html/scripts/reset-pricing-ab.php --confirm # выполнить
 *
 * orders.pricing_variant НЕ трогаем — историческая атрибуция выручки раунда 1 сохраняется,
 * её отсекает эпоха-фильтр дашборда.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

$confirm = in_array('--confirm', $argv, true);
$today   = date('Y-m-d');

echo "=== Сброс A/B-теста модели оплаты (раунд 2) ===\n";
echo $confirm ? "РЕЖИМ: ВЫПОЛНЕНИЕ (--confirm)\n\n" : "РЕЖИМ: СУХОЙ ПРОГОН (без --confirm — ничего не меняется)\n\n";

// ── 1. Снапшот раунда 1 ───────────────────────────────────────────────────────
$snapshot = [];
$snapshot['assigned'] = $db->query("
    SELECT pricing_variant v, COUNT(*) cnt FROM users
    WHERE pricing_variant IS NOT NULL GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_KEY_PAIR);

$snapshot['orders'] = $db->query("
    SELECT pricing_variant v, payment_status, COUNT(*) cnt, COALESCE(SUM(final_amount),0) revenue
    FROM orders WHERE pricing_variant IS NOT NULL
    GROUP BY pricing_variant, payment_status ORDER BY pricing_variant, payment_status
")->fetchAll(PDO::FETCH_ASSOC);

$snapshot['paying'] = $db->query("
    SELECT pricing_variant v, COUNT(DISTINCT user_id) cnt FROM orders
    WHERE pricing_variant IS NOT NULL AND payment_status='succeeded' AND final_amount > 0
    GROUP BY pricing_variant
")->fetchAll(PDO::FETCH_KEY_PAIR);

echo "Снапшот раунда 1:\n";
foreach (['A', 'B'] as $v) {
    $assigned = (int)($snapshot['assigned'][$v] ?? 0);
    $paying   = (int)($snapshot['paying'][$v] ?? 0);
    $conv     = $assigned > 0 ? round($paying / $assigned * 100, 2) : 0;
    echo "  Вариант $v: назначено $assigned, платящих $paying, конверсия {$conv}%\n";
}
echo "\n";

// Сохраняем снапшот в файл (запись истории — даже в сухом прогоне).
$snapDir = __DIR__ . '/ab-snapshots';
if (!is_dir($snapDir)) { @mkdir($snapDir, 0775, true); }
$snapFile = $snapDir . "/round1-{$today}.json";
@file_put_contents($snapFile, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "Снапшот сохранён: $snapFile\n\n";

// ── 2. Обнуление назначений ───────────────────────────────────────────────────
$toReset = (int)$db->query("SELECT COUNT(*) FROM users WHERE pricing_variant IS NOT NULL")->fetchColumn();

if ($confirm) {
    try {
        $db->beginTransaction();
        $affected = $db->exec("UPDATE users SET pricing_variant = NULL WHERE pricing_variant IS NOT NULL");
        $db->commit();
        echo "✓ Обнулено users.pricing_variant: $affected строк.\n";
    } catch (\Throwable $e) {
        if ($db->inTransaction()) { $db->rollBack(); }
        echo "ОШИБКА при обнулении (откат выполнен): " . $e->getMessage() . "\n";
        exit(1);
    }
} else {
    echo "[сухой прогон] Будет обнулено users.pricing_variant: $toReset строк.\n";
}

// ── 3. Новый секрет cookie ────────────────────────────────────────────────────
$newSecret = bin2hex(random_bytes(32));
echo "\nНовый секрет для ротации (инвалидирует cookie pm_v анонимов):\n";
echo "  PRICING_AB_SECRET=$newSecret\n";

// ── 4. Инструкции ─────────────────────────────────────────────────────────────
echo "\n=== ДАЛЬШЕ (вручную, в прод .env) ===\n";
echo "  1. PRICING_AB_SECRET=$newSecret\n";
echo "  2. PRICING_AB_EPOCH=$today   # дашборд /admin/ab-test учтёт заказы только с этой даты\n";
echo "  3. Перезапустить контейнер: docker restart pedagogy_web\n";
echo "  4. Проверить /admin/ab-test — счётчики назначенных обнулятся и поедут заново.\n";
if (!$confirm) {
    echo "\nЭто был СУХОЙ ПРОГОН. Для выполнения добавьте --confirm.\n";
}

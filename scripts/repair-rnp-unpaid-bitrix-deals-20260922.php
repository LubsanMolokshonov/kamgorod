#!/usr/bin/env php
<?php
/**
 * Разовая коррекция четырёх неоплаченных сделок, ошибочно попавших в РНП.
 *
 * Dry-run по умолчанию. Для применения: --apply.
 * Перед изменениями сохраняет ограниченный JSON-снимок строк в logs/.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

define('BASE_PATH', dirname(__DIR__));
require_once BASE_PATH . '/config/config.php';
$pdo = require BASE_PATH . '/config/database.php';
require_once BASE_PATH . '/classes/Bitrix24Integration.php';

$apply = in_array('--apply', $argv, true);
$dealIds = [1328910, 1388968, 1419186, 1476758];
$syntheticAmounts = [
    1388968 => 19168.24,
    1419186 => 3510.00,
    1476758 => 14990.00,
];
$enrollmentStatuses = [
    1388968 => 'cancelled',
    1419186 => 'enrolled',
    1476758 => 'cancelled',
];

$bitrix = new Bitrix24Integration();
if (!$bitrix->isConfigured()) {
    fwrite(STDERR, "Bitrix24 не настроен, коррекция остановлена.\n");
    exit(2);
}

$bitrixRows = [];
foreach ($dealIds as $dealId) {
    $deal = $bitrix->getDeal((string)$dealId);
    if (!$deal) {
        fwrite(STDERR, "Не удалось получить сделку #{$dealId}, коррекция остановлена.\n");
        exit(2);
    }
    $bitrixRows[$dealId] = [
        'ID'                => (int)($deal['ID'] ?? $dealId),
        'CATEGORY_ID'       => (int)($deal['CATEGORY_ID'] ?? -1),
        'STAGE_ID'          => (string)($deal['STAGE_ID'] ?? ''),
        'STAGE_SEMANTIC_ID' => (string)($deal['STAGE_SEMANTIC_ID'] ?? ''),
        'OPPORTUNITY'       => (float)($deal['OPPORTUNITY'] ?? 0),
        'CLOSEDATE'         => (string)($deal['CLOSEDATE'] ?? ''),
    ];
}

$placeholders = implode(',', array_fill(0, count($dealIds), '?'));
$markers = array_map(static fn(int $id): string => 'bitrix:' . $id, array_keys($syntheticAmounts));
$markerPlaceholders = implode(',', array_fill(0, count($markers), '?'));

$snapshot = [
    'captured_at' => date(DATE_ATOM),
    'deal_ids' => $dealIds,
    'bitrix' => $bitrixRows,
];

$stmt = $pdo->prepare(
    "SELECT id, order_number, total_amount, final_amount, payment_status,
            yookassa_payment_id, paid_at, created_at, updated_at
     FROM orders
     WHERE yookassa_payment_id IN ({$markerPlaceholders})
     ORDER BY id"
);
$stmt->execute($markers);
$snapshot['orders'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT oi.id, oi.order_id, oi.course_enrollment_id, oi.price
     FROM order_items oi
     JOIN orders o ON o.id = oi.order_id
     WHERE o.yookassa_payment_id IN ({$markerPlaceholders})
     ORDER BY oi.id"
);
$stmt->execute($markers);
$snapshot['order_items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT id, course_id, status, bitrix_lead_id, bitrix_stage,
            bitrix_stage_updated_at, created_at
     FROM course_enrollments
     WHERE bitrix_lead_id IN ({$placeholders})
     ORDER BY bitrix_lead_id, id"
);
$stmt->execute($dealIds);
$snapshot['enrollments'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$stmt = $pdo->prepare(
    "SELECT id, course_id, status, bitrix_lead_id, bitrix_stage,
            bitrix_stage_updated_at, created_at
     FROM course_consultations
     WHERE bitrix_lead_id IN ({$placeholders})
     ORDER BY bitrix_lead_id, id"
);
$stmt->execute($dealIds);
$snapshot['consultations'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

$ordersByMarker = [];
foreach ($snapshot['orders'] as $order) {
    $ordersByMarker[(string)$order['yookassa_payment_id']] = $order;
}
foreach ($syntheticAmounts as $dealId => $expectedAmount) {
    $marker = 'bitrix:' . $dealId;
    $order = $ordersByMarker[$marker] ?? null;
    if (!$order) {
        fwrite(STDERR, "Не найден синтетический заказ {$marker}, коррекция остановлена.\n");
        exit(3);
    }
    if (abs((float)$order['final_amount'] - $expectedAmount) > 0.01) {
        fwrite(STDERR, "Сумма {$marker} изменилась: {$order['final_amount']}, ожидалось {$expectedAmount}.\n");
        exit(3);
    }
}

echo ($apply ? "APPLY" : "DRY-RUN") . " — коррекция РНП по сделкам " . implode(', ', $dealIds) . "\n";
foreach ($dealIds as $dealId) {
    $deal = $bitrixRows[$dealId];
    echo sprintf(
        "  #%d: %.2f ₽, category=%d, stage=%s, semantic=%s\n",
        $dealId,
        $deal['OPPORTUNITY'],
        $deal['CATEGORY_ID'],
        $deal['STAGE_ID'],
        $deal['STAGE_SEMANTIC_ID']
    );
}

if (!$apply) {
    echo "Изменений нет. Для применения добавьте --apply.\n";
    exit(0);
}

$logDir = BASE_PATH . '/logs';
if (!is_dir($logDir) && !mkdir($logDir, 0750, true) && !is_dir($logDir)) {
    fwrite(STDERR, "Не удалось создать каталог резервных копий {$logDir}.\n");
    exit(4);
}
$backupFile = $logDir . '/rnp-unpaid-repair-backup-' . date('Ymd-His') . '.json';
$backupJson = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
if (file_put_contents($backupFile, $backupJson . "\n", LOCK_EX) === false) {
    fwrite(STDERR, "Не удалось записать резервную копию {$backupFile}.\n");
    exit(4);
}
chmod($backupFile, 0600);

$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare(
        "UPDATE orders
         SET payment_status = 'failed', paid_at = NULL
         WHERE yookassa_payment_id IN ({$markerPlaceholders})
           AND payment_status = 'succeeded'"
    );
    $stmt->execute($markers);
    $ordersChanged = $stmt->rowCount();

    $enrollmentsChanged = 0;
    $stmtEnrollment = $pdo->prepare(
        "UPDATE course_enrollments
         SET status = ?, bitrix_stage = ?, bitrix_stage_updated_at = NOW()
         WHERE bitrix_lead_id = ?"
    );
    foreach ($enrollmentStatuses as $dealId => $status) {
        $stmtEnrollment->execute([$status, $bitrixRows[$dealId]['STAGE_ID'], $dealId]);
        $enrollmentsChanged += $stmtEnrollment->rowCount();
    }

    $stmtConsultation = $pdo->prepare(
        "UPDATE course_consultations
         SET bitrix_stage = ?, bitrix_stage_updated_at = NOW()
         WHERE bitrix_lead_id = ?"
    );
    $stmtConsultation->execute([$bitrixRows[1328910]['STAGE_ID'], 1328910]);
    $consultationsChanged = $stmtConsultation->rowCount();

    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Коррекция отменена транзакцией: {$e->getMessage()}\n");
    exit(5);
}

echo "Резервная копия: {$backupFile}\n";
echo "Обновлено заказов: {$ordersChanged}; заявок: {$enrollmentsChanged}; консультаций: {$consultationsChanged}.\n";
echo "Готово.\n";

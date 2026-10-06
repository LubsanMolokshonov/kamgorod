#!/usr/bin/env php
<?php
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('CLI only');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/PaymentRefundAccounting.php';
require_once __DIR__ . '/../vendor/autoload.php';

function refundCheck(bool $ok, string $label): void {
    if (!$ok) { throw new RuntimeException($label); }
    echo "OK {$label}\n";
}

// Только временная таблица данной сессии; реальные данные не изменяются.
$migration = file_get_contents(__DIR__ . '/../database/migrations/190_yookassa_refund_totals.sql');
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $migration));
$db->exec(str_replace('CREATE TABLE IF NOT EXISTS', 'CREATE TEMPORARY TABLE IF NOT EXISTS', $migration));
$db->exec('CREATE TEMPORARY TABLE orders (yookassa_payment_id VARCHAR(64))');
$db->exec('CREATE TEMPORARY TABLE token_transactions (payment_id VARCHAR(64), reason VARCHAR(20))');
$db->exec("INSERT INTO orders VALUES ('00000000-0000-4000-8000-000000000001')");
$db->exec("INSERT INTO token_transactions VALUES ('00000000-0000-4000-8000-000000000002', 'purchase')");

$client = new class {
    public string $amount = '40.00';
    public string $currency = 'RUB';
    public string $refundStatus = 'succeeded';
    public string $paymentStatus = 'succeeded';
    public int $lookups = 0;
    public array $filters = [];
    public function getPaymentInfo($id) {
        $this->lookups++;
        return new YooKassa\Request\Payments\PaymentResponse([
            'id' => $id, 'status' => $this->paymentStatus, 'paid' => true,
            'created_at' => '2026-08-01T00:00:00Z', 'refundable' => true,
            'amount' => ['value' => '100.00', 'currency' => $this->currency],
            'refunded_amount' => ['value' => $this->amount, 'currency' => $this->currency],
        ]);
    }
    public function getRefundInfo($id) {
        return new YooKassa\Request\Refunds\RefundResponse([
            'id' => $id, 'payment_id' => '00000000-0000-4000-8000-000000000001', 'status' => $this->refundStatus,
            'created_at' => '2026-10-06T12:00:00Z', 'amount' => ['value' => '20.00', 'currency' => 'RUB'],
        ]);
    }
    public function getRefunds($filter) {
        $this->filters[] = $filter;
        $data = ['items' => [[
            'id' => isset($filter['cursor']) ? '00000000-0000-4000-8000-000000000005' : '00000000-0000-4000-8000-000000000004',
            'payment_id' => '00000000-0000-4000-8000-000000000001', 'status' => 'succeeded',
            'created_at' => '2026-10-06T12:00:00Z', 'amount' => ['value' => '20.00', 'currency' => 'RUB'],
        ]]];
        if (!isset($filter['cursor'])) { $data['next_cursor'] = 'page2'; }
        return new YooKassa\Request\Refunds\RefundsResponse($data);
    }
};
$accounting = new PaymentRefundAccounting($db, $client);
$read = static fn() => (float)$db->query("SELECT refunded_amount FROM yookassa_refund_totals WHERE payment_id='00000000-0000-4000-8000-000000000001'")->fetchColumn();
$dry = $accounting->syncRefund('00000000-0000-4000-8000-000000000004', false);
refundCheck($read() === 0.0 && $dry['linked'], 'Dry-run не пишет в БД');
$result = $accounting->syncRefund('00000000-0000-4000-8000-000000000004');
refundCheck($read() === 40.0 && !$result['full'], 'Учитывается накопленная частичная сумма из API');
$accounting->syncRefund('00000000-0000-4000-8000-000000000004');
refundCheck($read() === 40.0, 'Повтор webhook не удваивает возврат');
$client->amount = '60.00';
$accounting->syncRefund('00000000-0000-4000-8000-000000000005');
refundCheck($read() === 60.0, 'Следующий возврат увеличивает накопленную сумму');
$client->amount = '20.00';
$accounting->syncRefund('00000000-0000-4000-8000-000000000004');
refundCheck($read() === 60.0, 'Старый ответ API не откатывает сумму');
$client->amount = '100.00';
refundCheck($accounting->syncRefund('00000000-0000-4000-8000-000000000006')['full'] && $read() === 100.0, 'Полный возврат определяется по сумме');
refundCheck($accounting->syncPayment('00000000-0000-4000-8000-000000000002')['linked'], 'Возврат токенов находится без заказа');
refundCheck(!$accounting->syncPayment('00000000-0000-4000-8000-000000000003')['linked'], 'Несвязанный возврат сохраняется и отмечается');
$lookups = $client->lookups;
$stats = $accounting->reconcile(false, '2026-09-01T00:00:00Z');
refundCheck($stats['refunds'] === 2 && $stats['payments'] === 1 && $client->lookups === $lookups + 1, 'Пагинация и дедупликация платежей');
refundCheck(($client->filters[1]['cursor'] ?? '') === 'page2', 'Передан курсор следующей страницы');
refundCheck(($client->filters[1]['created_at.gte'] ?? '') === '2026-09-01T00:00:00Z', 'Окно сверки сохраняется при пагинации');
foreach (['pending', 'canceled'] as $status) {
    $client->refundStatus = $status;
    $rejected = false;
    try { $accounting->syncRefund('00000000-0000-4000-8000-000000000007'); } catch (RuntimeException $e) { $rejected = true; }
    refundCheck($rejected, "Неуспешный возврат {$status} не принимается");
}
$client->refundStatus = 'succeeded';
$client->amount = '101.00';
$rejected = false;
try { $accounting->syncPayment('00000000-0000-4000-8000-000000000007'); } catch (RuntimeException $e) { $rejected = true; }
refundCheck($rejected, 'Возврат сверх платежа не принимается');
$client->amount = '100.00';
$client->currency = 'USD';
$rejected = false;
try { $accounting->syncPayment('00000000-0000-4000-8000-000000000007'); } catch (RuntimeException $e) { $rejected = true; }
refundCheck($rejected, 'Другая валюта не смешивается с рублёвой выручкой');
echo "Refund accounting tests passed\n";

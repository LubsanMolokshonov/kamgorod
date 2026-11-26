<?php
declare(strict_types=1);
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
require_once __DIR__ . '/../classes/CoursePriceAB.php';
require_once __DIR__ . '/../includes/installment-helper.php';

$enrollment = ['price' => 12000, 'ab_variant' => 'A', 'program_type' => 'kpk', 'enrolled_at' => '2026-01-02 10:00:00'];
$start = strtotime('2026-01-02 10:00:00 UTC');
$expect = static function ($actual, $expected, string $message): void {
    if ($actual !== $expected) throw new RuntimeException($message);
};
$early = courseInstallmentQuote($enrollment, $start + 599);
$late = courseInstallmentQuote($enrollment, $start + 600);
$expect([$early['amount_kopecks'], $early['monthly'], $early['months']], [1080000, 900, 12], 'Условия в первые 10 минут');
$expect([$late['amount_kopecks'], $late['monthly']], [1200000, 1000], 'Граница 10 минут');
$enrollment['ab_variant'] = 'B';
$expect(courseInstallmentQuote($enrollment, $start)['available'], false, 'Порог применяется к итоговой цене по варианту записи');
$enrollment['installment_total'] = '10800.00';
$enrollment['installment_months'] = 12;
$enrollment['installment_monthly_amount'] = 900;
$saved = courseInstallmentQuote($enrollment, $start + 86400);
$expect([$saved['amount_kopecks'], $saved['monthly'], $saved['months']], [1080000, 900, 12], 'Принятые условия после смены цены и окончания скидки');
$expect(formatRub(11000.50), '11 000,50 ₽', 'Отображение копеек');
$db = new PDO('sqlite::memory:');
$db->exec('CREATE TABLE course_installment_quotes (enrollment_id INT, total_amount DECIMAL, months INT)');
$db->exec('INSERT INTO course_installment_quotes VALUES (1,10800,12)');
$row = ['id' => 1, 'status' => 'installment_requested', 'installment_monthly_amount' => 900, 'ab_variant' => 'B'];
$terms = courseDealTerms($db, $row, ['price' => 20000, 'program_type' => 'kpk']);
$expect([$terms['amount'], $terms['payment']['installment_monthly'], $terms['payment']['installment_months']],
        [10800.0,900,12], 'Первичная сделка использует снимок рассрочки');
echo "OK: рассрочка, граница скидки и сохранённые условия\n";

<?php
if (php_sapi_name() !== 'cli') { http_response_code(403); die('CLI only'); }
define('GROUP_DISCOUNT_TIERS', '[{"min":2,"max":4,"percent":10},{"min":5,"max":9,"percent":20},{"min":10,"max":30,"percent":30}]');
require __DIR__ . '/../includes/group-pricing.php';
require __DIR__ . '/../classes/LoyaltyDiscount.php';
$checks = 0;
function checkGroup(bool $ok, string $label): void { global $checks; $checks++; if (!$ok) throw new RuntimeException($label); }
function groupLines(int $count, float $price = 149, string $batch = 'one'): array {
    return array_map(static fn($id) => ['type'=>'registration','id'=>$id,'price'=>$price,'raw_data'=>['group_batch_id'=>$batch]], range(1,$count));
}
function minorSum(array $items): int { return array_sum(array_map(static fn($item)=>(int)round($item['price']*100),$items)); }

foreach ([2=>268,3=>402,4=>536,5=>596,9=>1073,10=>1043,30=>3129] as $size=>$expected) {
    $result = groupApplyDiscounts(groupLines($size), ['one'=>groupDiscountPercent($size)]);
    checkGroup(minorSum($result['items']) === $expected*100, 'Итог группы '.$size);
    checkGroup((int)round($result['discount']*100) + minorSum($result['items']) === $size*14900, 'Сумма и скидка '.$size);
    checkGroup(count(array_filter($result['items'], static fn($i)=>$i['in_group'])) === $size, 'Группа вне 2+1 '.$size);
    $calc = LoyaltyDiscount::calculateCartDiscount($expected);
    $receipt = LoyaltyDiscount::distributePricesWithDiscount(array_column($result['items'],'price'), $calc['amount']);
    checkGroup(array_sum(array_map(static fn($p)=>(int)round($p*100),$receipt)) === (int)round($calc['final']*100), 'Чек с персональной скидкой '.$size);
}
$partial = groupApplyDiscounts(groupLines(1), ['one'=>10]);
checkGroup(minorSum($partial['items']) === 13400, 'Тариф оставшейся позиции не меняется');
$two = groupApplyDiscounts(array_merge(groupLines(3,149,'one'),groupLines(3,149,'two')), ['one'=>10,'two'=>10]);
checkGroup(minorSum($two['items']) === 80400, 'Округление раздельно по двум группам');
$mixed = groupLines(3); $mixed[0]['price']=120.01; $mixed[1]['price']=170.03;
$result = groupApplyDiscounts($mixed,['one'=>10]);
checkGroup(minorSum($result['items']) === 39504, 'Разные цены и копейки');
$forward = groupApplyDiscounts(groupLines(9), ['one'=>20]);
$reverse = groupApplyDiscounts(array_reverse(groupLines(9)), ['one'=>20]);
$byId = static function($items) { $out=array_column($items,'price','id');ksort($out);return $out; };
checkGroup($byId($forward['items']) === $byId($reverse['items']), 'Порядок не меняет копейки конкретного участника');
$free = groupApplyDiscounts(groupLines(2,0),['one'=>30]);
checkGroup(minorSum($free['items']) === 0, 'Бесплатная группа');
$missing = groupApplyDiscounts(groupLines(2),[]);
checkGroup(minorSum($missing['items']) === 29800 && $missing['items'][0]['in_group'], 'Неизвестный тариф не дает 2+1');
$single = ['type'=>'registration','id'=>999,'price'=>149,'raw_data'=>[]];
$result=groupApplyDiscounts([$single],[]);
checkGroup($result['items'][0] === $single && $result['discount'] == 0, 'Одиночная позиция не меняется');
checkGroup(groupApplyDiscounts([],[]) === ['items'=>[],'discount'=>0], 'Пустая корзина');
checkGroup(groupFormatMoney(268) === '268', 'Целые рубли');
checkGroup(groupFormatMoney(119.2) === '119,20', 'Не скрывать копейки строки');
checkGroup(groupFormatMoney(804.75) === '804,75', 'Точная цена с персональной скидкой');
checkGroup(groupDiscountAmountMinor(51,100) === 51, 'Скидка не превышает цену');
try { groupDiscountAmountMinor(-1,10); throw new LogicException('Принята отрицательная сумма'); }
catch (InvalidArgumentException $e) { checkGroup(true,'Отклонена отрицательная сумма'); }
echo "PASS: $checks проверок групповой цены\n";

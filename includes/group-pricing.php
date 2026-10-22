<?php
/**
 * Групповое участие — расчёт прогрессивной скидки по размеру группы.
 *
 * Единый источник правды для:
 *  - серверного расчёта в ajax/save-group-registration.php (фиксация тарифа в participant_groups)
 *  - объёмной скидки в ajax/create-payment.php
 *  - JS-превью на странице ростера (тарифы отдаются в data-атрибуте через groupDiscountTiers()).
 *
 * Тарифы задаются в config.php константой GROUP_DISCOUNT_TIERS.
 */

if (!function_exists('groupDiscountTiers')) {
    /**
     * Возвращает массив тарифов [['min'=>int,'max'=>int,'percent'=>int], ...].
     */
    function groupDiscountTiers(): array
    {
        $tiers = json_decode(GROUP_DISCOUNT_TIERS, true);
        return is_array($tiers) ? $tiers : [];
    }
}

if (!function_exists('groupDiscountPercent')) {
    /**
     * Процент скидки для группы из $size участников.
     * Возвращает 0, если размер ниже минимального тарифа.
     */
    function groupDiscountPercent(int $size): int
    {
        $percent = 0;
        foreach (groupDiscountTiers() as $tier) {
            $min = (int)($tier['min'] ?? 0);
            $max = (int)($tier['max'] ?? PHP_INT_MAX);
            if ($size >= $min && $size <= $max) {
                $percent = (int)($tier['percent'] ?? 0);
            }
        }
        return $percent;
    }
}

/** Скидка группы округляется до рубля, как в форме ростера; расчеты — в копейках. */
function groupDiscountAmountMinor(int $grossMinor, int $percent): int
{
    if ($grossMinor < 0) throw new InvalidArgumentException('Сумма группы не может быть отрицательной');
    $percent = max(0, min(100, $percent));
    return min($grossMinor, intdiv($grossMinor * $percent + 5000, 10000) * 100);
}

/**
 * Применить зафиксированные тарифы групп, не меняя акцию для одиночных позиций.
 * Округление выполняется отдельно для каждого batch. Остаток копеек распределяется
 * по наибольшим дробным остаткам: сумма позиций точно равна показанной сумме группы.
 * При частичном удалении тариф остается прежним, считается текущий состав корзины.
 *
 * @return array{items:array,discount:float}
 */
function groupApplyDiscounts(array $items, array $percentByBatch): array
{
    $groups = [];
    $position = 0;
    foreach ($items as $index => $item) {
        $batch = $item['raw_data']['group_batch_id'] ?? null;
        if ($batch === null || $batch === '') continue;
        $minor = (int)round((float)$item['price'] * 100);
        if ($minor < 0) throw new InvalidArgumentException('Цена групповой позиции не может быть отрицательной');
        $items[$index]['in_group'] = true;
        $groups[$batch][] = [
            'index' => $index, 'minor' => $minor, 'position' => $position++,
            'key' => (string)($item['type'] ?? '') . ':' . (string)($item['id'] ?? $index),
        ];
    }
    $discountMinor = 0;
    foreach ($groups as $batch => $lines) {
        $gross = array_sum(array_column($lines, 'minor'));
        $discount = groupDiscountAmountMinor($gross, (int)($percentByBatch[$batch] ?? 0));
        $net = $gross - $discount;
        $allocated = 0;
        $shares = [];
        foreach ($lines as $line) {
            $product = $line['minor'] * $net;
            $share = $gross > 0 ? intdiv($product, $gross) : 0;
            $shares[] = $line + ['share' => $share, 'remainder' => $gross > 0 ? $product % $gross : 0];
            $allocated += $share;
        }
        usort($shares, static fn($a, $b) => ($b['remainder'] <=> $a['remainder'])
            ?: strcmp($a['key'], $b['key']) ?: ($a['position'] <=> $b['position']));
        $remaining = $net - $allocated;
        foreach ($shares as $rank => $share) {
            $items[$share['index']]['price'] = ($share['share'] + ($rank < $remaining ? 1 : 0)) / 100;
        }
        $discountMinor += $discount;
    }
    return ['items' => $items, 'discount' => $discountMinor / 100];
}

/** Не скрывать копейки, появившиеся при распределении суммы группы или скидках. */
function groupFormatMoney(float $amount): string
{
    $minor = (int)round($amount * 100);
    return number_format($minor / 100, abs($minor) % 100 === 0 ? 0 : 2, ',', ' ');
}

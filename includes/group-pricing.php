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

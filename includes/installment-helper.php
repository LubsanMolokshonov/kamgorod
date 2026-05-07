<?php
/**
 * Хелпер расчёта рассрочки 0% по курсам.
 *
 * Принимает УЖЕ финальную цену курса в ₽ (после CoursePriceAB::getAdjustedPrice
 * и любых других применённых скидок). Сам никаких скидок не считает.
 */

/**
 * @param float $finalPrice Итоговая цена курса в рублях
 * @return array{available:bool, monthly:int, months:int, total:int}
 */
function calculateInstallment(float $finalPrice): array {
    $months   = defined('COURSE_INSTALLMENT_MONTHS')   ? (int)COURSE_INSTALLMENT_MONTHS   : 12;
    $minPrice = defined('COURSE_INSTALLMENT_MIN_PRICE') ? (int)COURSE_INSTALLMENT_MIN_PRICE : 10000;

    $finalPrice = max(0.0, $finalPrice);
    $available  = $finalPrice >= $minPrice && $months > 0;

    return [
        'available' => $available,
        'monthly'   => $available ? (int)ceil($finalPrice / $months) : 0,
        'months'    => $months,
        'total'     => (int)round($finalPrice),
    ];
}

/** Форматирует целую сумму как «3 159 ₽» */
if (!function_exists('formatRub')) {
    function formatRub(int $amount): string {
        return number_format($amount, 0, ',', ' ') . ' ₽';
    }
}

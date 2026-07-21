<?php
/**
 * Детерминированное перемешивание массива по строковому seed.
 *
 * Один и тот же (массив, seed) всегда даёт одинаковый порядок — так контент
 * на странице стабилен между перезагрузками (важно для SEO: «плавающий» контент
 * читается краулером как нестабильный), но различается между страницами с разным
 * seed (напр. page_key посадочной). Реализация — Fisher–Yates с детерминированным
 * LCG, засеянным crc32(seed). Никакого rand()/shuffle().
 */

if (!function_exists('seededShuffle')) {
    function seededShuffle(array $items, string $seed): array
    {
        $items = array_values($items);
        $n = count($items);
        if ($n < 2) {
            return $items;
        }
        // LCG (параметры из Numerical Recipes) поверх crc32(seed).
        $state = crc32($seed) & 0xFFFFFFFF;
        for ($i = $n - 1; $i > 0; $i--) {
            $state = (1664525 * $state + 1013904223) & 0xFFFFFFFF;
            $j = $state % ($i + 1);
            $tmp = $items[$i];
            $items[$i] = $items[$j];
            $items[$j] = $tmp;
        }
        return $items;
    }
}

<?php
/**
 * Стабильный SKU для Product-микроразметки.
 * Синтетические ratingValue/reviewCount удалены: рейтинг строится
 * только по сохранённым одобренным reviews.
 */

if (!function_exists('syntheticSku')) {
    /**
     * Детерминированный артикул: латиница+цифры, без пробелов, 5–20 символов.
     * md5-hex (0-9a-f) — валидный набор; берём 12 символов в верхнем регистре.
     * @param string $key Стабильный ключ сущности
     * @return string Например "A1B2C3D4E5F6"
     */
    function syntheticSku(string $key): string {
        return strtoupper(substr(md5($key), 0, 12));
    }
}

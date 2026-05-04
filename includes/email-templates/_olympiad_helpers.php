<?php
/**
 * Хелперы для plain-text писем по олимпиадам.
 * Используются всеми olympiad_*.php шаблонами после миграции на plain-text
 * (антиспам Яндекса режет HTML — см. memory project_chain_emails_paused).
 */

if (!function_exists('olymp_bold_num')) {
    /**
     * Превращает все ASCII-цифры в строке в Unicode Mathematical Sans-Serif Bold (𝟬–𝟵).
     * Остальные символы (пробелы, запятые, минус, ₽) остаются как есть.
     */
    function olymp_bold_num($str) {
        static $map = [
            '0' => '𝟬', '1' => '𝟭', '2' => '𝟮', '3' => '𝟯', '4' => '𝟰',
            '5' => '𝟱', '6' => '𝟲', '7' => '𝟳', '8' => '𝟴', '9' => '𝟵',
        ];
        $s = (string)$str;
        $out = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            $out .= $map[$c] ?? $c;
        }
        return $out;
    }
}

if (!function_exists('olymp_price_fmt')) {
    /**
     * Форматирует цену с пробелами-разделителями и Unicode-жирными цифрами.
     */
    function olymp_price_fmt($price) {
        return olymp_bold_num(number_format((float)$price, 0, ',', ' '));
    }
}

if (!function_exists('olymp_append_utm')) {
    /**
     * Добавляет UTM-метки к URL (учитывая существующий query-string).
     */
    function olymp_append_utm($url, $utm) {
        return $url . (strpos($url, '?') !== false ? '&' : '?') . $utm;
    }
}

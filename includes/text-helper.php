<?php
/**
 * Текстовые утилиты отображения.
 */

if (!function_exists('fix_mojibake')) {
    /**
     * Чинит «двойную кодировку» (mojibake) — когда UTF-8-байты однажды были
     * прочитаны как Windows-1252 / ISO-8859-1 и повторно перекодированы в UTF-8.
     * Так в БД попадают строки вида «Ð¡Ñ‚Ð°Ñ€Ñ‚Ð¾Ð²Ð°Ñ ...» вместо «Стартовая ...»
     * (например, при ручной вставке через подключение без utf8mb4).
     *
     * Логика безопасна для корректных данных: если в строке уже есть кириллица
     * или она чисто ASCII — ничего не меняем. Чиним только когда обратное
     * преобразование даёт валидный UTF-8 с кириллицей.
     *
     * @param string|null $s
     * @return string
     */
    function fix_mojibake(?string $s): string
    {
        if ($s === null || $s === '') {
            return (string)$s;
        }

        // Не валидный UTF-8 — оставляем как есть, чтобы не делать хуже.
        if (!mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        // Уже содержит настоящую кириллицу → строка корректна.
        if (preg_match('/\p{Cyrillic}/u', $s)) {
            return $s;
        }

        // Чистый ASCII (тех. пометки, e-mail и т.п.) → чинить нечего.
        if (!preg_match('/[^\x00-\x7F]/', $s)) {
            return $s;
        }

        // Обратное преобразование: UTF-8 → байты исходной 8-битной кодировки.
        foreach (['Windows-1252', 'ISO-8859-1'] as $enc) {
            $repaired = @mb_convert_encoding($s, $enc, 'UTF-8');
            if ($repaired !== false
                && $repaired !== ''
                && mb_check_encoding($repaired, 'UTF-8')
                && preg_match('/\p{Cyrillic}/u', $repaired)) {
                return $repaired;
            }
        }

        return $s;
    }
}

if (!function_exists('strip_foreign_scripts')) {
    /**
     * Убирает из строки иероглифы и слоговые письменности, недопустимые в
     * русскоязычном учебном материале. ИИ-модели иногда «протекают» токенами
     * других языков (напр. «Оценить自己的 работу» — 自己的 это китайское «свой»).
     * Латиница НЕ трогается (нужна для терминов, аббревиатур, уроков ин. языка),
     * убираются только восточноазиатские письменности и редкие чужие алфавиты.
     *
     * @param string|null $s
     * @return string
     */
    function strip_foreign_scripts(?string $s): string
    {
        if ($s === null || $s === '') {
            return (string)$s;
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            return $s;
        }

        // CJK (китайский/японский Han), кана, хангыль, бопомофо, CJK-пунктуация,
        // полноширинные формы, а также арабица/иврит/прочие нелатинские/некириллические.
        $pattern = '/[\x{0590}-\x{05FF}'   // иврит
            . '\x{0600}-\x{06FF}'           // арабица
            . '\x{2E80}-\x{2FFF}'           // CJK радикалы
            . '\x{3000}-\x{303F}'           // CJK пунктуация
            . '\x{3040}-\x{30FF}'           // хирагана + катакана
            . '\x{3100}-\x{312F}'           // бопомофо
            . '\x{3130}-\x{318F}'           // хангыль чамо
            . '\x{3190}-\x{31BF}'           // канбун/расширения
            . '\x{3200}-\x{4DBF}'           // вложенные CJK + расширение A
            . '\x{4E00}-\x{9FFF}'           // CJK унифицированные иероглифы
            . '\x{A000}-\x{A4CF}'           // и
            . '\x{AC00}-\x{D7AF}'           // хангыль слоги
            . '\x{F900}-\x{FAFF}'           // CJK совместимость
            . '\x{FF00}-\x{FFEF}]'          // полноширинные формы
            . '/u';

        $cleaned = preg_replace($pattern, '', $s);
        if ($cleaned === null || $cleaned === $s) {
            return $s;
        }

        // Подчищаем артефакты удаления: двойные пробелы и пробел перед пунктуацией.
        $cleaned = preg_replace('/[ \t]{2,}/u', ' ', $cleaned);
        $cleaned = preg_replace('/ +([,.;:!?»)])/u', '$1', $cleaned);

        return $cleaned;
    }
}

if (!function_exists('strip_foreign_scripts_deep')) {
    /**
     * Рекурсивно прогоняет strip_foreign_scripts() по всем строкам массива/значения.
     * Используется для очистки JSON-структуры сгенерированного материала.
     *
     * @param mixed $value
     * @return mixed
     */
    function strip_foreign_scripts_deep($value)
    {
        if (is_string($value)) {
            return strip_foreign_scripts($value);
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $value[$k] = strip_foreign_scripts_deep($v);
            }
        }
        return $value;
    }
}

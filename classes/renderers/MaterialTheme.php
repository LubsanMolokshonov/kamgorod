<?php
/**
 * MaterialTheme — единый источник цветов/шрифтов для рендереров материалов.
 *
 * Зеркалит токены assets/css/redesign.css, чтобы on-page вёрстка и
 * скачиваемые файлы (PDF/DOCX/PPTX) выглядели одинаково.
 *
 * Цвета хранятся БЕЗ «#» (так их ждут PHPWord и PHPPresentation).
 * Для CSS в mPDF используйте css($hex) — добавит «#».
 */

class MaterialTheme
{
    // Indigo
    const INDIGO_50  = 'ecefff';
    const INDIGO_100 = 'd6dcff';
    const INDIGO_200 = 'aab6f3';
    const INDIGO_600 = '1e3aa8';
    const INDIGO_700 = '182f8a';
    const INDIGO_800 = '12246d';

    // Accent
    const VIOLET_500 = '8a5cf2';

    // Ink (нейтральные)
    const INK_900 = '0e1330';
    const INK_700 = '2a3056';
    const INK_500 = '5a608a';
    const INK_200 = 'dde0ec';
    const INK_100 = 'eceef6';
    const INK_50  = 'f6f7fb';

    const WHITE = 'ffffff';

    // Шрифты. PDF использует встроенный в mPDF freesans (кириллица),
    // DOCX/PPTX — Word-native Calibri, чтобы не падать на читательский фолбэк.
    const PDF_FONT  = 'freesans';
    const DOC_FONT  = 'Calibri';

    /** Hex с решёткой для CSS (mPDF). */
    public static function css(string $hex): string
    {
        return '#' . ltrim($hex, '#');
    }
}

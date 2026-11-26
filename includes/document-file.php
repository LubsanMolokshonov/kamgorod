<?php
/** Проверка завершённого PDF перед повторным использованием и выдачей заказа. */
function isIssuedDocumentPdf(string $path): bool
{
    if (!is_file($path) || !is_readable($path)) return false;
    $file = fopen($path, 'rb');
    if (!$file) return false;
    try {
        if (fread($file, 5) !== '%PDF-') return false;
        $length = fstat($file)['size'];
        if ($length < 12 || fseek($file, max(0, $length - 1024)) !== 0) return false;
        return str_contains((string)stream_get_contents($file), '%%EOF');
    } finally {
        fclose($file);
    }
}

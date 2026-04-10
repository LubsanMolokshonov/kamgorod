<?php
/**
 * Генерация логотипа «Sk Участник» программно через GD
 * Зелёный скруглённый квадрат + белый текст
 * Результат: assets/images/skolkovo-logo-white.png
 */

$basePath = __DIR__ . '/..';
$outPath = $basePath . '/assets/images/skolkovo-logo-white.png';
$fontBold = $basePath . '/assets/fonts/Montserrat-Bold.ttf';

if (!file_exists($fontBold)) {
    $fontBold = $basePath . '/vendor/mpdf/mpdf/ttfonts/DejaVuSans-Bold.ttf';
}

$w = 700;
$h = 200;

$img = imagecreatetruecolor($w, $h);
imagesavealpha($img, true);
imagealphablending($img, true);
$transparent = imagecolorallocatealpha($img, 0, 0, 0, 127);
imagefill($img, 0, 0, $transparent);

// Зелёный квадрат Sk
// Оригинал: верхний левый, нижний левый, нижний правый — скруглены; верхний правый — прямой
$squareSize = 170;
$r = 38;
$green = imagecolorallocate($img, 163, 209, 49);

$x1 = 10; $y1 = 15;
$x2 = $x1 + $squareSize; $y2 = $y1 + $squareSize;

// Заливаем фигуру по частям:
// Верхняя полоса (от скруглённого левого до прямого правого угла)
imagefilledrectangle($img, $x1 + $r, $y1, $x2, $y1 + $r, $green);
// Средняя полоса (на всю ширину)
imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $green);
// Нижняя полоса (между двумя скруглёнными углами)
imagefilledrectangle($img, $x1 + $r, $y2 - $r, $x2 - $r, $y2, $green);

// 3 скруглённых угла (верхний правый — прямой)
imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $green);   // верхний левый
imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $green);   // нижний левый
imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $green);   // нижний правый

// Буквы «Sk» белым поверх зелёного
$white = imagecolorallocate($img, 255, 255, 255);
$skFontSize = 72;
$bbox = imagettfbbox($skFontSize, 0, $fontBold, 'Sk');
$skW = abs($bbox[2] - $bbox[0]);
$skH = abs($bbox[7] - $bbox[1]);
$skX = $x1 + (int)(($squareSize - $skW) / 2);
$skY = $y1 + (int)(($squareSize + $skH) / 2);
imagettftext($img, $skFontSize, 0, $skX, $skY, $white, $fontBold, 'Sk');

// Текст «Участник» белым справа
$textFontSize = 58;
$textX = $x2 + 25;
$bbox = imagettfbbox($textFontSize, 0, $fontBold, 'Участник');
$textH = abs($bbox[7] - $bbox[1]);
$textY = $y1 + (int)(($squareSize + $textH) / 2);
imagettftext($img, $textFontSize, 0, $textX, $textY, $white, $fontBold, 'Участник');

// Сохраняем
imagealphablending($img, false);
imagesavealpha($img, true);
imagepng($img, $outPath, 6);
imagedestroy($img);

echo "Готово: {$outPath} (" . number_format(filesize($outPath) / 1024, 1) . " KB)\n";

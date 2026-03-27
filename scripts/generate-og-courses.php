<?php
/**
 * Генерация OG-картинки для раздела курсов
 * Запуск: php scripts/generate-og-courses.php
 * Результат: assets/images/og-courses.jpg (1200x630)
 */

$width = 1200;
$height = 630;
$img = imagecreatetruecolor($width, $height);

// Цвета (голубая тема сайта)
$bgColor = imagecolorallocate($img, 0, 102, 221); // #0066DD
$white = imagecolorallocate($img, 255, 255, 255);
$lightBlue = imagecolorallocate($img, 51, 153, 255); // #3399FF

// Фон — градиент (имитация через прямоугольники)
for ($y = 0; $y < $height; $y++) {
    $r = (int)(44 + ($y / $height) * (0 - 44));   // 2C -> 00
    $g = (int)(62 + ($y / $height) * (102 - 62));  // 3E -> 66
    $b = (int)(80 + ($y / $height) * (221 - 80));  // 50 -> DD
    $color = imagecolorallocate($img, max(0, $r), max(0, $g), max(0, $b));
    imageline($img, 0, $y, $width, $y, $color);
}

// Декоративный круг
imagefilledellipse($img, 1050, 150, 300, 300, $lightBlue);
imagesetthickness($img, 3);
imageellipse($img, 200, 550, 200, 200, $lightBlue);

// Текст (системный шрифт)
$fontFile = null;
$possibleFonts = [
    '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
    '/System/Library/Fonts/Helvetica.ttc',
    '/System/Library/Fonts/SFNSDisplay.ttf',
];
foreach ($possibleFonts as $f) {
    if (file_exists($f)) {
        $fontFile = $f;
        break;
    }
}

if ($fontFile) {
    // Заголовок
    imagettftext($img, 42, 0, 80, 200, $white, $fontFile, "Курсы повышения");
    imagettftext($img, 42, 0, 80, 260, $white, $fontFile, "квалификации");

    // Подзаголовок
    $lightWhite = imagecolorallocate($img, 200, 220, 255);
    imagettftext($img, 22, 0, 80, 330, $lightWhite, $fontFile, "Дистанционное обучение с удостоверением");
    imagettftext($img, 22, 0, 80, 365, $lightWhite, $fontFile, "установленного образца");

    // Название портала
    imagettftext($img, 18, 0, 80, 550, $lightWhite, $fontFile, "fgos.pro — Педагогический портал");
} else {
    // Fallback: встроенные шрифты GD
    imagestring($img, 5, 80, 180, "Kursy povysheniya kvalifikacii", $white);
    imagestring($img, 4, 80, 320, "fgos.pro", $white);
}

// Сохранение
$outputPath = __DIR__ . '/../assets/images/og-courses.jpg';
imagejpeg($img, $outputPath, 90);
imagedestroy($img);

echo "OG-картинка создана: $outputPath\n";
echo "Размер: " . filesize($outputPath) . " байт\n";

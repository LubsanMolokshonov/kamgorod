<?php
/**
 * Генерация PNG-шаблона «веер дипломов» для рекламных картинок
 * Использует rsvg-convert для рендеринга SVG → PNG, затем GD для композиции
 *
 * Запуск: php scripts/generate-diploma-fan.php
 * Результат: assets/images/diploma-fan-template.png
 */

$basePath = __DIR__ . '/..';
$svgDir = $basePath . '/assets/images/diplomas/previews';
$outputPath = $basePath . '/assets/images/diploma-fan-template.png';
$tmpDir = sys_get_temp_dir();

// Параметры веера (масштабировано под 600×390 итоговый размер)
$canvasW = 600;
$canvasH = 390;
$diplomaW = 160; // ширина каждого диплома
$diplomaH = 226; // высота (A4 пропорции ≈ 1:1.414)

// Позиции и углы поворота (из CSS competition-detail.php, масштабировано)
$diplomas = [
    ['file' => 'diploma-1.svg', 'left' => 60,  'top' => 90,  'angle' => 15],
    ['file' => 'diploma-2.svg', 'left' => 85,  'top' => 70,  'angle' => 9],
    ['file' => 'diploma-3.svg', 'left' => 110, 'top' => 55,  'angle' => 3],
    ['file' => 'diploma-4.svg', 'left' => 140, 'top' => 40,  'angle' => -3],
    ['file' => 'diploma-5.svg', 'left' => 170, 'top' => 30,  'angle' => -9],
    ['file' => 'diploma-6.svg', 'left' => 200, 'top' => 20,  'angle' => -15],
];

echo "Генерация шаблона веера дипломов...\n";

// Шаг 1: Конвертируем SVG → PNG
$pngFiles = [];
foreach ($diplomas as $i => $d) {
    $svgPath = $svgDir . '/' . $d['file'];
    $pngPath = $tmpDir . '/diploma-' . ($i + 1) . '.png';

    if (!file_exists($svgPath)) {
        echo "  ОШИБКА: не найден {$d['file']}\n";
        exit(1);
    }

    $cmd = sprintf(
        'rsvg-convert -w %d -h %d -f png -o %s %s 2>&1',
        $diplomaW,
        $diplomaH,
        escapeshellarg($pngPath),
        escapeshellarg($svgPath)
    );

    exec($cmd, $output, $returnCode);
    if ($returnCode !== 0) {
        echo "  ОШИБКА rsvg-convert для {$d['file']}: " . implode("\n", $output) . "\n";
        exit(1);
    }

    $pngFiles[] = $pngPath;
    echo "  Конвертирован: {$d['file']} → PNG ({$diplomaW}×{$diplomaH})\n";
}

// Шаг 2: Создаём холст с прозрачным фоном
$canvas = imagecreatetruecolor($canvasW, $canvasH);
imagesavealpha($canvas, true);
imagealphablending($canvas, true);
$transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
imagefill($canvas, 0, 0, $transparent);

// Шаг 3: Накладываем дипломы с поворотом
foreach ($diplomas as $i => $d) {
    $pngPath = $pngFiles[$i];
    $diploma = imagecreatefrompng($pngPath);
    if (!$diploma) {
        echo "  ОШИБКА: не удалось загрузить PNG #{$i}\n";
        continue;
    }

    // Добавляем тень
    $shadow = imagecreatetruecolor($diplomaW + 6, $diplomaH + 6);
    imagesavealpha($shadow, true);
    imagealphablending($shadow, true);
    $transp = imagecolorallocatealpha($shadow, 0, 0, 0, 127);
    imagefill($shadow, 0, 0, $transp);

    // Рисуем тень (тёмный полупрозрачный прямоугольник)
    $shadowColor = imagecolorallocatealpha($shadow, 0, 0, 0, 90);
    imagefilledrectangle($shadow, 3, 3, $diplomaW + 5, $diplomaH + 5, $shadowColor);

    // Накладываем диплом поверх тени
    imagecopy($shadow, $diploma, 0, 0, 0, 0, $diplomaW, $diplomaH);
    imagedestroy($diploma);

    // Добавляем скруглённую рамку (белая обводка 2px)
    $borderColor = imagecolorallocatealpha($shadow, 255, 255, 255, 40);
    imagerectangle($shadow, 0, 0, $diplomaW - 1, $diplomaH - 1, $borderColor);

    // Поворачиваем
    $rotated = imagerotate($shadow, $d['angle'], imagecolorallocatealpha($shadow, 0, 0, 0, 127));
    imagesavealpha($rotated, true);
    imagealphablending($rotated, false);
    imagedestroy($shadow);

    // Вычисляем позицию с учётом увеличения размера после поворота
    $rotW = imagesx($rotated);
    $rotH = imagesy($rotated);
    $offsetX = ($rotW - $diplomaW) / 2;
    $offsetY = ($rotH - $diplomaH) / 2;

    $dstX = (int)($d['left'] - $offsetX);
    $dstY = (int)($d['top'] - $offsetY);

    imagealphablending($canvas, true);
    imagecopy($canvas, $rotated, $dstX, $dstY, 0, 0, $rotW, $rotH);
    imagedestroy($rotated);

    echo "  Наложен диплом #{$i}: left={$d['left']}, top={$d['top']}, angle={$d['angle']}°\n";
}

// Шаг 4: Сохраняем результат
imagealphablending($canvas, false);
imagesavealpha($canvas, true);
imagepng($canvas, $outputPath, 6); // compression level 6
imagedestroy($canvas);

// Очищаем временные файлы
foreach ($pngFiles as $f) {
    @unlink($f);
}

$size = filesize($outputPath);
echo "\nГотово! Сохранён: {$outputPath}\n";
echo "Размер: " . number_format($size / 1024, 1) . " KB ({$canvasW}×{$canvasH})\n";

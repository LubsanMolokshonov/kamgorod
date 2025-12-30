<?php
/**
 * Simple mPDF test with Cyrillic
 */

require_once __DIR__ . '/vendor/autoload.php';

use Mpdf\Mpdf;

// Тестовые данные
$name = "Иванов Иван Иванович";
$competition = "Культурное наследие России";

// Путь к PNG шаблону
$templatePath = __DIR__ . '/assets/images/diplomas/templates/diploma-template-1.png';
$imageData = base64_encode(file_get_contents($templatePath));
$imageSrc = "data:image/png;base64," . $imageData;

// HTML с правильной кодировкой
$html = '<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
    <style>
        body { margin: 0; padding: 0; font-family: DejaVuSans; }
        .container { position: relative; width: 210mm; height: 297mm; }
        .bg { position: absolute; width: 100%; height: 100%; }
        .field { position: absolute; text-align: center; }
        .name { left: 35mm; top: 121mm; width: 140mm; font-size: 18pt; color: #1f2937; font-weight: bold; }
        .comp { left: 30mm; top: 165mm; width: 150mm; font-size: 16pt; color: #1f2937; }
    </style>
</head>
<body>
    <div class="container">
        <img src="' . $imageSrc . '" class="bg" />
        <div class="field name">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>
        <div class="field comp">' . htmlspecialchars($competition, ENT_QUOTES, 'UTF-8') . '</div>
    </div>
</body>
</html>';

// Создаем PDF
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 0,
    'margin_right' => 0,
    'margin_top' => 0,
    'margin_bottom' => 0,
    'default_font' => 'dejavusans',
    'autoScriptToLang' => true,
    'autoLangToFont' => true
]);

$mpdf->WriteHTML($html);
$mpdf->Output(__DIR__ . '/uploads/diplomas/test-simple.pdf', 'F');

echo "Тестовый PDF создан: uploads/diplomas/test-simple.pdf\n";

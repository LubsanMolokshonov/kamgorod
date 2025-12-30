<?php
/**
 * Test mPDF text overlay on image
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/classes/Database.php';

use Mpdf\Mpdf;

// Получаем данные регистрации
$stmt = $db->prepare("
    SELECT r.*, u.full_name, u.organization, u.city, c.title as competition_name
    FROM registrations r
    JOIN users u ON r.user_id = u.id
    JOIN competitions c ON r.competition_id = c.id
    WHERE r.id = 6
");
$stmt->execute();
$reg = $stmt->fetch(PDO::FETCH_ASSOC);

// Путь к шаблону
$templatePath = __DIR__ . '/assets/images/diplomas/templates/diploma-template-1.png';

// Создаем mPDF
$mpdf = new Mpdf([
    'mode' => 'utf-8',
    'format' => 'A4',
    'margin_left' => 0,
    'margin_right' => 0,
    'margin_top' => 0,
    'margin_bottom' => 0,
    'default_font' => 'dejavusans'
]);

// Добавляем фоновое изображение на всю страницу
$mpdf->SetDefaultBodyCSS('background', "url('$templatePath')");
$mpdf->SetDefaultBodyCSS('background-image-resize', 6);

// Простой HTML с текстом в нужных позициях
$html = '
<style>
    body { font-family: DejaVuSans; }
    .name {
        position: absolute;
        left: 35mm;
        top: 121mm;
        width: 140mm;
        text-align: center;
        font-size: 18pt;
        font-weight: bold;
        color: #1f2937;
    }
    .competition {
        position: absolute;
        left: 30mm;
        top: 165mm;
        width: 150mm;
        text-align: center;
        font-size: 16pt;
        font-weight: bold;
        color: #1f2937;
    }
</style>

<div class="name">' . htmlspecialchars($reg['full_name'], ENT_QUOTES, 'UTF-8') . '</div>
<div class="competition">' . htmlspecialchars($reg['competition_name'], ENT_QUOTES, 'UTF-8') . '</div>
';

$mpdf->WriteHTML($html);
$mpdf->Output(__DIR__ . '/uploads/diplomas/test-overlay.pdf', 'F');

echo "✓ Тестовый PDF создан: uploads/diplomas/test-overlay.pdf\n";
echo "Имя: " . $reg['full_name'] . "\n";
echo "Конкурс: " . $reg['competition_name'] . "\n";

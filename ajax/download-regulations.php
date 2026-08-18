<?php
/**
 * Download Competition Regulations (PDF)
 *
 * Отдаёт положение о конкурсе файлом. Текст — тот же, что в модалке на странице
 * конкурса (includes/regulations-content.php), просто свёрстанный под печать.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../classes/Database.php';
require_once __DIR__ . '/../classes/Competition.php';
require_once __DIR__ . '/../includes/regulations-content.php';
require_once __DIR__ . '/../vendor/autoload.php';

$competitionId = (int)($_GET['competition_id'] ?? 0);

if (!$competitionId) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    die('ID конкурса не указан');
}

$competitionObj = new Competition($db);
$competition = $competitionObj->getById($competitionId);

if (!$competition) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Конкурс не найден');
}

// Имя файла: транслит слага — латиница уже в slug, кириллицу в заголовок не тащим
$filename = 'polozhenie-' . preg_replace('/[^a-z0-9\-]/', '', $competition['slug']) . '.pdf';

try {
    $mpdf = new \Mpdf\Mpdf([
        'mode'           => 'utf-8',
        'format'         => 'A4',
        'margin_left'    => 18,
        'margin_right'   => 18,
        'margin_top'     => 16,
        'margin_bottom'  => 16,
        'default_font'   => 'dejavusans',
        'tempDir'        => sys_get_temp_dir(),
    ]);
    $mpdf->SetTitle('Положение о конкурсе «' . $competition['title'] . '»');
    $mpdf->SetAuthor('ФГОС-Практикум');
    $mpdf->SetHTMLFooter(regulationsPdfFooterHtml());
    $mpdf->WriteHTML(buildRegulationsPdfHtml($competition));
    $mpdf->Output($filename, \Mpdf\Output\Destination::DOWNLOAD);
} catch (Exception $e) {
    error_log('download-regulations: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    die('Не удалось сформировать документ. Напишите нам на info@fgos.pro — пришлём положение письмом.');
}
